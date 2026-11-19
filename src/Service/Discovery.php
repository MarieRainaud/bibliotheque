<?php
namespace App\Service;
final class Discovery
{
    public function __construct(private Library $library,private GoogleBooks $google) {}
    public static function normalize(string $text): string
    {
        $text=mb_strtolower($text,'UTF-8');
        $text=strtr($text,[
            'à'=>'a','â'=>'a','ä'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i',
            'ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ÿ'=>'y','œ'=>'oe','æ'=>'ae',
        ]);
        $plain=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$text);
        return trim(preg_replace('/[^a-z0-9]+/',' ',strtolower($plain?:$text)));
    }
    public static function volume(string $title): ?float
    {
        if (preg_match('/\b(?:tome|volume|vol\.?|book|livre|t\.)\s*(?:n[°o]\s*)?(\d{1,4}(?:[.,]\d+)?)/iu',$title,$m)) { return (float)str_replace(',','.',$m[1]); }
        return null;
    }
    public static function candidate(array $series,array $item,array $known): ?array
    {
        if (!SeriesCatalogue::matches($series,$item)) { return null; }
        $volume=SeriesCatalogue::volume($item);
        foreach ($known as $book) {
            if (($item['google_id'] && $item['google_id']===$book['google_id']) || ($item['isbn'] && $item['isbn']===$book['isbn'])) { return null; }
            if ($volume!==null && $book['volume']!==null) {
                if ($volume===(float)$book['volume']) { return null; }
                continue;
            }
            if (SeriesCatalogue::workKey($book['title'])===SeriesCatalogue::workKey($item['title'])) { return null; }
        }
        // One fingerprint for a numbered tome across print/digital editions.
        $fingerprint=hash('sha256',$series['language'].'|'.($volume!==null?'volume:'.$volume:'title:'.SeriesCatalogue::workKey($item['title'])));
        return ['fingerprint'=>$fingerprint,'volume'=>$volume,'reason'=>$volume!==null?'Même auteur, même série et numéro de tome encore absent.':'Même auteur et nom de série. Le rattachement reste à vérifier.'];
    }
    public function check(string $owner,bool $force=false): array
    {
        $due=$this->library->query('SELECT * FROM series WHERE owner=? AND followed=1 AND (attempted_at IS NULL OR attempted_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)) AND (?=1 OR checked_at IS NULL OR checked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)) ORDER BY checked_at, id LIMIT 1',[$owner,$force?1:0]);
        $checked=0;$found=0;$errors=[];
        foreach ($due as $series) {
            $series['language']='fr';
            // Atomic claim avoids duplicate requests from multiple browser tabs.
            $claim=$this->library->db()->prepare('UPDATE series SET attempted_at=UTC_TIMESTAMP() WHERE id=? AND (attempted_at IS NULL OR attempted_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))');$claim->execute([$series['id']]);
            if (!$claim->rowCount()) { continue; }
            try {
                $query=SeriesCatalogue::query($series['name'],$series['author']);
                $result=$this->google->search($query,0,false,$force,'fr',40);
                $latest=$this->google->search($query,0,true,$force,'fr',20);
                $known=$this->library->books($owner,['series'=>$series['id']]);
                $volumes=[];
                foreach ([...$result['items'],...$latest['items']] as $item) {
                    if (empty($series['description']) && !empty($item['series_description']) && SeriesCatalogue::matches($series,$item)) {
                        $description=BookSynopsis::french($item['series_description'],40);
                        if ($description!=='') {
                            $this->library->run('UPDATE series SET description=? WHERE id=? AND owner=?',[$description,$series['id'],$owner]);
                            $series['description']=$description;
                        }
                    }
                    if (!$item['google_id'] || !($candidate=self::candidate($series,$item,$known))) { continue; }
                    $fingerprint=$candidate['fingerprint'];
                    $date=$item['release']??$item['published'];
                    $future=SeriesCatalogue::upcoming($date,(bool)($item['preorder']??false));
                    $prior=$volumes[$fingerprint]??null;
                    if ($prior && ((!$prior['future'] && $future) || ($prior['future']===$future && $prior['date']!=='' && ($date==='' || $prior['date']<=$date)))) { continue; }
                    $volumes[$fingerprint]=['item'=>$item,'candidate'=>$candidate,'date'=>$date,'future'=>$future];
                }
                $statement=$this->library->db()->prepare("INSERT INTO suggestion(series_id,fingerprint,google_id,title,authors,published,language,volume,reason,preorder) VALUES(?,?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE published=IF(VALUES(published)='',published,VALUES(published)),preorder=VALUES(preorder),google_id=IF(state='pending',VALUES(google_id),google_id)");
                foreach ($volumes as $entry) {
                    $item=$entry['item'];$candidate=$entry['candidate'];
                    $statement->execute([$series['id'],$candidate['fingerprint'],$item['google_id'],$item['title'],$item['authors'],$entry['date'],'fr',$candidate['volume'],$candidate['reason'],!empty($item['preorder'])?1:0]);
                    if ($statement->rowCount()===1) { $found++; }
                }
                $this->library->run('UPDATE series SET checked_at=UTC_TIMESTAMP(),check_error=NULL WHERE id=?',[$series['id']]);$checked++;
            } catch (\Throwable $e) { $message='La vérification n’a pas abouti. Réessayer dans quelques minutes.';$this->library->run('UPDATE series SET check_error=? WHERE id=?',[$message,$series['id']]);$errors[]=$message; }
        }
        $remaining=(int)($this->library->query('SELECT COUNT(*) count FROM series WHERE owner=? AND followed=1 AND (attempted_at IS NULL OR attempted_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)) AND (?=1 OR checked_at IS NULL OR checked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY))',[$owner,$force?1:0])[0]['count']??0);
        return ['checked'=>$checked,'found'=>$found,'remaining'=>$remaining,'errors'=>$errors,'count'=>$this->library->upcomingCount($owner)];
    }
}
