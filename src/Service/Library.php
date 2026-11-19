<?php
namespace App\Service;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
final class Library
{
    public function __construct(private Database $database) {}
    public function db(): \PDO { return $this->database->connection(); }
    public function query(string $sql,array $args=[]): array { $q=$this->db()->prepare($sql);$q->execute($args);return $q->fetchAll(); }
    public function run(string $sql,array $args=[]): void { $q=$this->db()->prepare($sql);$q->execute($args); }
    public function books(string $owner,array $filters=[]): array
    {
        $where=['b.owner=?'];$args=[$owner];
        foreach (preg_split('/\s+/u',trim($filters['q']??''),-1,PREG_SPLIT_NO_EMPTY) as $word) { $where[]="CONCAT_WS(' ',b.title,b.authors,b.isbn,s.name,b.genre) LIKE ?";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$word).'%';$where[array_key_last($where)].=" ESCAPE '!'"; }
        foreach (['status'=>'r.status','format'=>'b.format','series'=>'b.series_id','owned'=>'b.owned'] as $key=>$column) { if (isset($filters[$key]) && $filters[$key]!=='') { $where[]=$column.'=?';$args[]=$filters[$key]; } }
        $sort=['recent'=>'b.created_at DESC,b.id DESC','title'=>'b.title,b.id','author'=>'b.authors,b.title','progress'=>'r.updated_at DESC,b.id DESC'][$filters['sort']??'recent']??'b.created_at DESC,b.id DESC';
        return $this->query("SELECT b.*,s.name series_name,r.id reading_id,r.status,r.position,r.rating,r.started,r.finished,r.notes,r.review,COALESCE(b.read_override,EXISTS(SELECT 1 FROM reading history WHERE history.book_id=b.id AND history.status='finished')) ever_read FROM book b LEFT JOIN series s ON s.id=b.series_id JOIN reading r ON r.id=(SELECT MAX(rr.id) FROM reading rr WHERE rr.book_id=b.id) WHERE ".implode(' AND ',$where)." ORDER BY ".$sort,$args);
    }
    public function book(string $owner,int $id): array
    {
        $row=$this->query("SELECT b.*,s.name series_name,s.author series_author,COALESCE(b.read_override,EXISTS(SELECT 1 FROM reading history WHERE history.book_id=b.id AND history.status='finished')) ever_read FROM book b LEFT JOIN series s ON s.id=b.series_id WHERE b.id=? AND b.owner=?",[$id,$owner])[0]??null;
        if (!$row) { throw new NotFoundHttpException('Livre introuvable.'); }return $row;
    }
    public function readings(string $owner,int $bookId): array
    {
        $this->book($owner,$bookId);
        return $this->query('SELECT * FROM reading WHERE book_id=? ORDER BY id DESC',[$bookId]);
    }
    public function series(string $owner): array
    {
        return $this->query('SELECT s.*,(SELECT COUNT(*) FROM book b WHERE b.series_id=s.id AND b.owner=s.owner) book_count,(SELECT MAX(volume) FROM book b WHERE b.series_id=s.id AND b.owner=s.owner) last_volume,(SELECT COUNT(*) FROM suggestion n WHERE n.series_id=s.id AND n.state=?) suggestion_count FROM series s WHERE owner=? ORDER BY s.name',['pending',$owner]);
    }
    public function seriesId(string $owner,string $name,string $author,string $language='fr',bool $follow=true,string $description=''): ?int
    {
        if ($name==='') { return null; }
        $this->run('INSERT INTO series(owner,name,author,language,followed,description) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),followed=GREATEST(followed,VALUES(followed)),description=COALESCE(NULLIF(description,\'\'),VALUES(description))',[$owner,$name,$author,$language,$follow?1:0,$description?:null]);
        return (int)$this->db()->lastInsertId();
    }
    public function save(string $owner,array $data,?int $id=null): int
    {
        $book=BookInput::book($data);
        $reading=$id===null?BookInput::reading($data,$book):null;
        $this->db()->beginTransaction();
        try {
            if ($id!==null) {
                $previous=$this->book($owner,$id);
                if ($previous['unit']==='percent') {
                    $this->convertPercentProgress($id,$book['total'],$book['unit']);
                } else {
                    $latest=$this->readings($owner,$id)[0];
                    if ($book['total']!==null && (float)$latest['position']>(float)$book['total']) { throw new \InvalidArgumentException('Le total est inférieur à la progression actuelle. Modifier d’abord la progression.'); }
                }
            }
            $seriesId=$this->seriesId($owner,$book['series_name'],$book['series_author'],$book['language'],$id===null&&(bool)($data['follow_series']??true),BookInput::text($data['series_description']??'',15000));
            unset($book['series_name'],$book['series_author']);
            $book['series_id']=$seriesId;
            if (!$seriesId) { $book['volume']=null; }
            if ($id===null) {
                $columns=array_keys($book);
                $this->run('INSERT INTO book(owner,'.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns)+1,'?')).')',[$owner,...array_values($book)]);
                $id=(int)$this->db()->lastInsertId();
                $this->insertReading($id,$reading);
            } else {
                $this->run('UPDATE book SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($book))).' WHERE id=? AND owner=?',[...array_values($book),$id,$owner]);
            }
            $this->db()->commit();return $id;
        } catch (\Throwable $e) { $this->db()->rollBack();if ($e instanceof \PDOException && $e->getCode()==='23000') { throw new \InvalidArgumentException('Ce livre est déjà présent dans ce format.'); }throw $e; }
    }
    private function convertPercentProgress(int $bookId,?float $total,string $unit): void
    {
        if ($total===null) {
            if ($this->query('SELECT 1 FROM reading r LEFT JOIN progress p ON p.reading_id=r.id WHERE r.book_id=? AND (r.position>0 OR p.position>0) LIMIT 1',[$bookId])) {
                throw new \InvalidArgumentException('Renseigner le total pour convertir la progression déjà enregistrée.');
            }
            return;
        }
        $precision=$unit==='minutes'||floor($total)!==$total?2:0;
        $this->run('UPDATE progress p JOIN reading r ON r.id=p.reading_id SET p.position=ROUND(p.position * ? / 100, ?) WHERE r.book_id=?',[$total,$precision,$bookId]);
        $this->run('UPDATE reading SET position=ROUND(position * ? / 100, ?) WHERE book_id=?',[$total,$precision,$bookId]);
    }
    private function insertReading(int $bookId,array $reading): void
    {
        $this->run('INSERT INTO reading(book_id,'.implode(',',array_keys($reading)).') VALUES('.implode(',',array_fill(0,count($reading)+1,'?')).')',[$bookId,...array_values($reading)]);
        $this->run('INSERT INTO progress(reading_id,position) VALUES(?,?)',[(int)$this->db()->lastInsertId(),$reading['position']]);
    }
    public function updateReading(string $owner,int $bookId,array $data): void
    {
        $book=$this->book($owner,$bookId);$latest=$this->readings($owner,$bookId)[0];
        if ($book['unit']==='percent') { $data['position']=$latest['position']; }
        $reading=BookInput::reading($data,$book);
        $this->db()->beginTransaction();
        try {
            $this->writeReading($latest,$reading);
            if ($reading['status']==='finished') { $this->run('UPDATE book SET read_override=NULL WHERE id=? AND owner=?',[$bookId,$owner]); }
            $this->db()->commit();
        } catch (\Throwable $e) { $this->db()->rollBack();throw $e; }
    }
    private function writeReading(array $latest,array $reading): void
    {
        $this->run('UPDATE reading SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($reading))).' WHERE id=?',[...array_values($reading),$latest['id']]);
        if ((float)$reading['position']!==(float)$latest['position']) {
            $this->run('INSERT INTO progress(reading_id,position) VALUES(?,?)',[$latest['id'],$reading['position']]);
        }
    }

    public function setFlag(string $owner,int $id,string $flag,bool $value): void
    {
        if (!in_array($flag,['owned','read'],true)) { throw new \InvalidArgumentException('État de lecture non valide.'); }
        $this->db()->beginTransaction();
        try {
            if (!$this->query('SELECT id FROM book WHERE id=? AND owner=? FOR UPDATE',[$id,$owner])) {
                throw new NotFoundHttpException('Livre introuvable.');
            }
            if ($flag==='owned') {
                $this->run('UPDATE book SET owned=? WHERE id=? AND owner=?',[$value?1:0,$id,$owner]);
            } else {
                $book=$this->book($owner,$id);
                $readings=$this->readings($owner,$id);
                $current=$readings[0];
                $completed=array_filter($readings,static fn(array $reading): bool => $reading['status']==='finished');
                if ($value) {
                    if (!$completed) {
                        $this->writeReading($current,BookInput::reading(array_replace($current,['status'=>'finished']),$book));
                    }
                    $this->run('UPDATE book SET read_override=NULL WHERE id=? AND owner=?',[$id,$owner]);
                } else {
                    if ($current['status']==='finished') {
                        $position=$book['total']===null?$current['position']:($this->query('SELECT position FROM progress WHERE reading_id=? AND position<? ORDER BY id DESC LIMIT 1',[$current['id'],$book['total']])[0]['position']??0);
                        $status=($current['started']||(float)$position>0)?'reading':'to_read';
                        $this->writeReading($current,BookInput::reading(array_replace($current,['status'=>$status,'position'=>$position]),$book));
                        unset($completed[0]);
                    }
                    $this->run('UPDATE book SET read_override=? WHERE id=? AND owner=?',[$completed?0:null,$id,$owner]);
                }
            }
            $this->db()->commit();
        } catch (\Throwable $e) { $this->db()->rollBack();throw $e; }
    }

    public function reread(string $owner,int $id): void
    {
        $this->db()->beginTransaction();
        try {
            if (!$this->query('SELECT id FROM book WHERE id=? AND owner=? FOR UPDATE',[$id,$owner])) { throw new NotFoundHttpException('Livre introuvable.'); }
            $book=$this->book($owner,$id);$latest=$this->readings($owner,$id)[0];
            if ($latest['status']!=='finished') { throw new \InvalidArgumentException('Terminer la lecture actuelle avant de commencer une relecture.'); }
            $this->insertReading($id,BookInput::reading(['status'=>'reading'],$book));
            $this->db()->commit();
        } catch (\Throwable $e) { $this->db()->rollBack();throw $e; }
    }

    public function followBookSeries(string $owner,int $id,array $series): void
    {
        $this->db()->beginTransaction();
        try {
            $book=$this->book($owner,$id);
            $seriesId=$this->seriesId($owner,$series['name'],$series['author'],'fr',true,$series['description']??'');
            $this->run('UPDATE book SET series_id=?,volume=? WHERE id=? AND owner=?',[$seriesId,$series['volume']??$book['volume'],$id,$owner]);
            $this->db()->commit();
        } catch (\Throwable $e) { $this->db()->rollBack();throw $e; }
    }

    public function remove(string $owner,int $id): void { $this->book($owner,$id);$this->run('DELETE FROM book WHERE id=? AND owner=?',[$id,$owner]); }
    public function suggestions(string $owner,string $state='pending'): array
    {
        return $this->query('SELECT n.*,s.name series_name,s.author series_author FROM suggestion n JOIN series s ON s.id=n.series_id WHERE s.owner=? AND n.state=? AND n.language=? ORDER BY n.discovered_at DESC,n.id DESC',[$owner,$state,'fr']);
    }
    public function seriesVolumes(string $owner): array
    {
        return $this->query("SELECT n.* FROM suggestion n JOIN series s ON s.id=n.series_id WHERE s.owner=? AND n.language='fr' AND n.state<>'dismissed'",[$owner]);
    }
    public function upcomingCount(string $owner): int
    {
        $entries=$this->query("SELECT n.published,n.preorder FROM suggestion n JOIN series s ON s.id=n.series_id WHERE s.owner=? AND s.followed=1 AND n.state='pending' AND n.language='fr'",[$owner]);
        return count(array_filter($entries,static fn(array $entry): bool => SeriesCatalogue::upcoming($entry['published'],(bool)$entry['preorder'])));
    }
    public function suggestion(string $owner,int $id): array
    {
        $row=$this->query('SELECT n.*,s.name series_name,s.author series_author FROM suggestion n JOIN series s ON s.id=n.series_id WHERE s.owner=? AND n.id=?',[$owner,$id])[0]??null;
        if (!$row) { throw new NotFoundHttpException('Proposition introuvable.'); }return $row;
    }
    public function suggestionState(string $owner,int $id,string $state): void { $this->suggestion($owner,$id);$this->run('UPDATE suggestion SET state=? WHERE id=?',[$state,$id]); }
    public function seedDemo(string $owner): void
    {
        if ($this->books($owner)) { return; }
        $examples=[
            ['title'=>'La cartographe des brumes','authors'=>'Élise Beaumont','series_name'=>'Les îles suspendues','volume'=>1,'genre'=>'Imaginaire','format'=>'paper','unit'=>'pages','total'=>360,'position'=>148,'status'=>'reading','owned'=>1,'notes'=>'Une ambiance douce et mystérieuse.'],
            ['title'=>'Le jardin des heures','authors'=>'Maya Delcourt','genre'=>'Roman','format'=>'digital','unit'=>'pages','total'=>280,'status'=>'to_read','owned'=>1],
            ['title'=>'Les îles suspendues - La cité du vent','authors'=>'Élise Beaumont','series_name'=>'Les îles suspendues','volume'=>2,'genre'=>'Imaginaire','format'=>'paper','unit'=>'pages','total'=>410,'status'=>'to_read'],
            ['title'=>'Sur les chemins du silence','authors'=>'Noé Laurent','genre'=>'Voyage','format'=>'audio','unit'=>'minutes','total'=>480,'status'=>'finished','started'=>'2026-09-02','finished'=>'2026-09-18','rating'=>4,'review'=>'Une belle invitation à ralentir.','owned'=>1],
            ['title'=>'Une saison pour soi','authors'=>'Camille Aubert','genre'=>'Roman','format'=>'paper','unit'=>'pages','total'=>248,'status'=>'finished','started'=>'2026-08-01','finished'=>'2026-08-12','rating'=>5,'owned'=>1]
        ];
        foreach ($examples as $book) { $this->save($owner,DemoBooks::fill($book+['owner'=>$owner])); }
        $this->run('UPDATE series SET followed=0 WHERE owner=?',[$owner]);
        $series=$this->series($owner)[0];
        $future=(new \DateTimeImmutable('+3 months',new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        $this->run('INSERT INTO suggestion(series_id,fingerprint,google_id,title,authors,published,language,volume,reason,preorder) VALUES(?,?,?,?,?,?,?,?,?,?)',
            [$series['id'],hash('sha256','demo-tome-3'),'','Les îles suspendues - Les jardins d’écume','Élise Beaumont',$future,'fr',3,'',1]);
    }
}
