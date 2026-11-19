<?php
namespace App\Service;

final class CatalogueWorks
{
    public function __construct(private Database $database,private GoogleBooks $catalogue) {}

    public static function title(array $book): string
    {
        $title=$book['title'];
        while (preg_match('~\s*[\[(]([^\])]+)[\])]\s*$~u',$title,$match)) {
            $suffix=Discovery::normalize($match[1]);
            if (preg_match('/\b(?:tome|volume|partie|livre|book|vol)\s+\d+|\d+\s*$/',$suffix)
                && !preg_match('/\b(?:edition|anniversaire|impression)\b/',$suffix)) { break; }
            $title=trim(substr($title,0,-strlen($match[0])));
        }
        return trim(preg_replace('~\s*[-–—:,]\s*(?:édition\b|livre audio\b|grand format\b|format poche\b).*$~iu','',$title)) ?: $book['title'];
    }

    public static function key(array $book): string
    {
        $author=Discovery::normalize(explode(',',$book['authors'])[0]);
        $words=explode(' ',$author);
        $identity=count($words)>1 ? substr($words[0],0,1).' '.end($words) : $author;
        if ($identity==='') { $identity=($book['isbn']??'')?:($book['google_id']??''); }
        return hash('sha256',Discovery::normalize(self::title($book)).'|'.$identity);
    }

    public function results(string $query,int $page,array $library): array
    {
        $result=$this->catalogue->searchFrench($query,$page);
        $groups=[];$owned=[];
        foreach ($library as $book) { $owned[self::key($book)]??=(int)$book['id']; }
        foreach ($result['items'] as $book) {
            if ($book['google_id']==='') { continue; }
            $groups[self::key($book)][$book['google_id']]=$book;
        }
        $this->remember($groups);
        $items=[];
        foreach ($groups as $key=>$editions) {
            $book=reset($editions);
            $summary=BookSynopsis::best($editions+$this->known($key));
            $items[]=[
                'work'=>$key,'google_id'=>$book['google_id'],'title'=>self::title($book),
                'authors'=>$book['authors'],'language'=>$book['language'],'library_id'=>$owned[$key]??null,
                'excerpt'=>BookSynopsis::excerpt($summary),
            ];
        }
        return ['items'=>$items,'more'=>$result['more']];
    }

    public function description(array $book,bool $fetch=true): string
    {
        $summary=BookSynopsis::best([$book]);
        if (mb_strlen($summary)>=220 || empty($book['google_id'])) { return $summary; }
        $known=BookSynopsis::best($this->known(self::key($book)));
        if (mb_strlen($known)>mb_strlen($summary)) { $summary=$known; }
        if (!$fetch || mb_strlen($summary)>=220) { return $summary; }
        $available=BookSynopsis::best($this->findEditions($book));
        return mb_strlen($available)>mb_strlen($summary)?$available:$summary;
    }

    public function editions(array $book): array
    {
        $reference=$book['google_id'];
        $known=$this->findEditions($book);
        $editions=[];$seen=[];
        foreach ($known as $edition) {
            $isbn=$edition['isbn'];
            $identity=$isbn!=='' ? 'isbn:'.$isbn : 'ref:'.$edition['google_id'];
            if (isset($seen[$identity])) { continue; }
            $seen[$identity]=true;
            $editions[]=[
                'reference'=>$edition['google_id'],'title'=>$edition['title'],'subtitle'=>$edition['subtitle'],
                'publisher'=>$edition['publisher'],'published'=>$edition['published'],'pages'=>$edition['page_count']??null,
                'isbn'=>$isbn,'current'=>$edition['google_id']===$reference,
            ];
        }
        return $editions;
    }

    private function findEditions(array $book): array
    {
        $key=self::key($book);
        $reference=$book['google_id'];
        $known=[$reference=>$book]+$this->known($key);
        $query='intitle:'.SeriesCatalogue::term(self::title($book));
        if ($book['authors']!=='') { $query.=' inauthor:'.SeriesCatalogue::term(trim(explode(',',$book['authors'])[0])); }
        try {
            $result=$this->catalogue->search($query,0,false,false,'fr',40);
            foreach ($result['items'] as $edition) {
                if ($edition['google_id']!=='' && GoogleBooks::isFrench($edition['language']) && self::key($edition)===$key) {
                    $known[$edition['google_id']]??=$edition;
                }
            }
            $this->remember([$key=>$known]);
        } catch (\Throwable) { /* Les éditions déjà recensées restent disponibles. */ }

        return $known;
    }

    private function known(string $key): array
    {
        $statement=$this->database->connection()->prepare('SELECT payload FROM api_cache WHERE cache_key=? AND expires_at>UTC_TIMESTAMP()');
        $statement->execute([$this->cacheKey($key)]);
        $known=json_decode($statement->fetchColumn()?:'{}',true,512,JSON_THROW_ON_ERROR);
        return array_map(static fn(array|string $edition): array => is_string($edition)?json_decode($edition,true,512,JSON_THROW_ON_ERROR):$edition,$known);
    }

    private function remember(array $groups): void
    {
        if (!$groups) { return; }
        $rows=[];$args=[];
        foreach ($groups as $key=>$editions) {
            $rows[]='(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))';
            $args[]=$this->cacheKey($key);$args[]=json_encode((object)array_map(static fn(array $edition): string => json_encode($edition,JSON_THROW_ON_ERROR),$editions),JSON_THROW_ON_ERROR);
        }
        $sql='INSERT INTO api_cache(cache_key,payload,expires_at) VALUES '.implode(',',$rows).
            ' ON DUPLICATE KEY UPDATE payload=IF(expires_at>UTC_TIMESTAMP(),JSON_MERGE_PATCH(payload,VALUES(payload)),VALUES(payload)),expires_at=VALUES(expires_at)';
        $this->database->connection()->prepare($sql)->execute($args);
    }

    private function cacheKey(string $key): string { return hash('sha256','catalogue-editions:'.$key); }
}
