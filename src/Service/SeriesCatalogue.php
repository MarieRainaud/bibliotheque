<?php
namespace App\Service;

final class SeriesCatalogue
{
    public function __construct(private Library $library,private GoogleBooks $catalogue) {}

    public static function query(string $name,string $author): string
    {
        return 'intitle:'.self::term($name).' inauthor:'.self::term($author);
    }

    public static function term(string $text): string
    {
        return '"'.trim(str_replace(['"', '\\'], ' ', $text)).'"';
    }

    public static function authorMatches(string $left,string $right): bool
    {
        foreach (explode(',',$left) as $a) {
            foreach (explode(',',$right) as $b) {
                $aWords=explode(' ',Discovery::normalize($a));$bWords=explode(' ',Discovery::normalize($b));
                if ($aWords===$bWords && $aWords[0]!=='') { return true; }
                if (count($aWords)<2 || count($aWords)!==count($bWords) || end($aWords)!==end($bWords)) { continue; }
                foreach ($aWords as $i=>$word) {
                    $other=$bWords[$i];
                    if ($word!==$other && !(min(strlen($word),strlen($other))===1 && $word[0]===$other[0])) { continue 2; }
                }
                return true;
            }
        }
        return false;
    }

    public static function workKey(string $title): string
    {
        $title=preg_replace('~\s*[\[(][^\])]+[\])]\s*$~u','',$title);
        $title=preg_replace('~\s*(?:[,:–—-]\s*)?(?:tome|volume|vol\.)\s*\d+(?:[.,]\d+)?\s*$~iu','',$title);
        return Discovery::normalize($title);
    }

    public static function derivative(string $title): bool
    {
        return (bool)preg_match('/\b(?:coffret|integrale|collection complete|omnibus|collector|workbook|guide|resume|analyse|coloriage|quiz|recettes|scenario|encyclopedie|texte officiel de la production|adaptation theatrale|texte de la piece)\b/',Discovery::normalize($title));
    }

    public static function volume(array $book): ?float
    {
        $n=str_replace(',','.',$book['series_number']??'');
        if (is_numeric($n) && $n>0 && $n<=9999) { return (float)$n; }
        $number=Discovery::volume($book['title'].' '.($book['subtitle']??''));
        if ($number!==null) { return $number; }

        // French titles in the official order: https://www.harrypotter.com/discover/books
        if (self::authorMatches('J. K. Rowling',$book['authors']??'')) {
            $numbered=[
                'harry potter a l ecole des sorciers'=>1,
                'harry potter et la chambre des secrets'=>2,
                'harry potter et le prisonnier d azkaban'=>3,
                'harry potter et la coupe de feu'=>4,
                'harry potter et l ordre du phenix'=>5,
                'harry potter et le prince de sang mele'=>6,
                'harry potter et les reliques de la mort'=>7,
            ];
            $verified=$numbered[Discovery::normalize(CatalogueWorks::title($book))]??null;
            if ($verified!==null) { return (float)$verified; }
        }

        $ordinals=['premier'=>1,'second'=>2,'deuxieme'=>2,'troisieme'=>3,'quatrieme'=>4,'cinquieme'=>5,'sixieme'=>6,'septieme'=>7,'huitieme'=>8,'neuvieme'=>9,'dixieme'=>10];
        $matches=[];
        foreach (preg_split('/[.!?;\n]+/u',$book['description']??'') as $sentence) {
            $sentence=Discovery::normalize($sentence);
            if (preg_match('/(?:^(?:le |ce |voici le |dans ce )?|\b(?:dans ce|decouvrez le) )('.implode('|',array_keys($ordinals)).')(?: et dernier)? (?:tome|volume|volet)\b/',$sentence,$found)) {
                $matches[$ordinals[$found[1]]]=true;
            }
        }
        return count($matches)===1?(float)array_key_first($matches):null;
    }

    public static function matches(array $series,array $book): bool
    {
        if (!GoogleBooks::isFrench($book['language']??'') || !self::authorMatches($series['author'],$book['authors']) || self::derivative($book['title'].' '.($book['subtitle']??''))) {
            return false;
        }
        $name=Discovery::normalize($series['name']);
        $title=Discovery::normalize($book['title']);
        $subtitle=Discovery::normalize($book['subtitle']??'');
        $hint=Discovery::normalize($book['series_hint']??'');
        return $name!=='' && ($name===$hint || str_starts_with($title.' ',$name.' ') || str_starts_with($subtitle.' ',$name.' ') || str_ends_with(' '.$title,' '.$name));
    }

    public function identify(array $book,array $known): ?array
    {
        $author=trim(explode(',',$book['authors'])[0]);
        if ($author==='' || self::derivative($book['title'].' '.($book['subtitle']??''))) { return null; }
        $volume=self::volume($book);
        foreach ($known as $series) {
            if (self::matches($series,$book)) {
                return ['id'=>$series['id'],'name'=>$series['name'],'author'=>$series['author'],'volume'=>$volume,'description'=>$series['description']??'','followed'=>(bool)$series['followed']];
            }
        }
        $name=trim($book['series_hint']??'');
        if ($name==='') {
            foreach ([$book['title'],$book['subtitle']??''] as $text) {
                if (preg_match('/^(.+?)(?:\s+|\s*[,:(\-–—]\s*)(?:tome|volume|vol\.)\s*\d+/iu',$text,$m)) {
                    $name=rtrim($m[1]," \t,:-");break;
                }
            }
        }
        if ($name==='' && preg_match('/\b(?:tome|volume|volet)\b[^.!?]{0,45}\bde la (?:série|saga|trilogie)\s+[«“"]\s*([^»”"]{2,120})/iu',$book['description']??'',$m)) {
            $name=trim($m[1]);
        }
        if ($name!=='') {
            return ['name'=>$name,'author'=>$author,'volume'=>$volume,'description'=>BookSynopsis::french($book['series_description']??'',40),'followed'=>false];
        }
        $hasPrefix=preg_match('/^(.+?)(?:\s+(?:et|à|contre|dans)\s+|\s+[-–—]\s+|\s*:\s+)/iu',$book['title'],$m);
        $name=trim($hasPrefix?$m[1]:$book['title']);
        $words=array_filter(explode(' ',Discovery::normalize($name)),static fn(string $word): bool => strlen($word)>2 && !in_array($word,['les','des','une','aux'],true));
        if (count($words)<($hasPrefix?2:1) || count($words)>($hasPrefix?6:4) || mb_strlen($name)>90) { return null; }

        $series=['name'=>$name,'author'=>$author,'volume'=>$volume,'description'=>BookSynopsis::french($book['series_description']??'',40),'followed'=>false];
        try {
            $result=$this->catalogue->search(self::query($name,$author),0,false,false,'fr',40);
        } catch (\Throwable) { return null; }
        $works=[self::workKey($book['title'])=>true];
        foreach ($result['items'] as $item) {
            if (self::matches($series,$item)) {
                $works[self::workKey($item['title'])]=true;
                if (self::workKey($item['title'])===self::workKey($book['title'])) { $series['volume']??=self::volume($item); }
            }
        }
        return count($works)>=3?$series:null;
    }

    public static function upcoming(string $date,bool $preorder=false): bool
    {
        $today=(new \DateTimeImmutable('today',new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        if (!preg_match('/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/D',$date)) { return $preorder; }
        $comparison=strcmp($date,substr($today,0,strlen($date)));
        return $comparison>0 || ($preorder && $comparison===0 && strlen($date)<10);
    }

    public function dashboard(string $owner,?int $id=null): array
    {
        $series=$this->library->series($owner);
        if ($id!==null) { $series=array_values(array_filter($series,static fn(array $group): bool => (int)$group['id']===$id)); }
        if (!$series) { return []; }
        $books=[];$entries=[];$unlinked=[];
        foreach ($this->library->books($owner) as $book) {
            if ($book['series_id']) { $books[$book['series_id']][]=$book; }
            else { $unlinked[]=$book; }
        }
        foreach ($this->library->seriesVolumes($owner) as $entry) { $entries[$entry['series_id']][]=$entry; }
        return array_map(function(array $group) use ($books,$unlinked,$entries,$owner): array {
            $matching=array_filter($unlinked,static fn(array $book): bool => self::matches($group,$book));
            return $this->assemble($group,[...($books[$group['id']]??[]),...$matching],$entries[$group['id']]??[],$owner);
        },$series);
    }

    public function preview(string $owner,array $series,array $book): array
    {
        $query=self::query($series['name'],$series['author']);
        $items=[$book];$checkError=null;
        foreach ([false,true] as $newest) {
            try {
                $result=$this->catalogue->search($query,0,$newest,false,'fr',$newest?20:40);
                foreach ($result['items'] as $item) {
                    if ($item['google_id']!=='' && self::matches($series,$item)) { $items[]=$item; }
                }
            } catch (\Throwable) { $checkError='Certaines parutions n’ont pas pu être vérifiées.'; }
        }
        $entries=array_map(static fn(array $item): array => [
            'id'=>null,'title'=>$item['title'],'volume'=>self::volume($item),'google_id'=>$item['google_id'],
            'published'=>$item['release']??$item['published'],'preorder'=>$item['preorder']??false,'genre'=>$item['genre']??'',
        ],$items);
        $owned=array_filter($this->library->books($owner),static fn(array $item): bool => $item['google_id']===$book['google_id'] || self::matches($series,$item));
        return $this->assemble($series+['id'=>null,'check_error'=>$checkError],$owned,$entries,$owner);
    }

    private function assemble(array $group,array $books,array $entries,string $owner): array
    {
        $rows=[];
        foreach ($books as $book) {
            $date=$book['release_date']?:$book['published'];
            $this->merge($rows,[
                'title'=>$book['title'],'volume'=>$book['volume']??self::volume($book),'reference'=>$book['google_id'],'genre'=>$book['genre'],
                'book_id'=>$book['id'],'suggestion_id'=>null,'owned'=>(bool)$book['owned'],'read'=>(bool)$book['ever_read'],
                'date'=>$date,'upcoming'=>self::upcoming($date,(bool)$book['preorder']),
            ]);
        }
        foreach ($entries as $entry) {
            $this->merge($rows,[
                'title'=>$entry['title'],'volume'=>$entry['volume']??self::volume($entry+['authors'=>$group['author']]),'reference'=>$entry['google_id'],'genre'=>$entry['genre']??'',
                'book_id'=>null,'suggestion_id'=>$entry['id'],'owned'=>false,'read'=>false,
                'date'=>$entry['published'],'upcoming'=>self::upcoming($entry['published'],(bool)$entry['preorder']),
            ]);
        }
        usort($rows,static fn(array $a,array $b): int => ($a['volume']??INF)<=>($b['volume']??INF) ?: strcmp($a['date'],$b['date']) ?: strnatcasecmp($a['title'],$b['title']));
        $group['published_books']=array_values(array_filter($rows,static fn(array $row): bool => !$row['upcoming']));
        $group['upcoming_books']=array_values(array_filter($rows,static fn(array $row): bool => $row['upcoming']));
        usort($group['upcoming_books'],static fn(array $a,array $b): int => strcmp($a['date']?:'9999',$b['date']?:'9999'));
        $group['read_count']=count(array_filter($group['published_books'],static fn(array $row): bool => $row['read']));
        $group['owned_count']=count(array_filter($group['published_books'],static fn(array $row): bool => $row['owned']));
        $group['cover']=null;
        $genre='';$genreCatalogue=false;
        foreach ($group['published_books'] as $row) {
            if ($genre==='') { $genre=$row['genre'];$genreCatalogue=!empty($row['reference']); }
            if ((float)$row['volume']===1.0 && ($row['reference'] || str_starts_with($owner,'demo:'))) {
                $group['cover']=['title'=>$row['title'],'google_id'=>$row['reference'],'owner'=>$owner];
                if ($row['genre']!=='') { $genre=$row['genre'];$genreCatalogue=!empty($row['reference']); }
                break;
            }
        }
        if ($genre==='') {
            foreach ($rows as $row) { if ($row['genre']!=='') { $genre=$row['genre'];$genreCatalogue=!empty($row['reference']);break; } }
        }
        $group['genre']=GenreLabels::format($genre,$genreCatalogue);
        $group['description']=($group['description']??'')?:DemoBooks::seriesDescription($owner,$group['name'],$group['author']);
        $group['excerpt']=BookSynopsis::excerpt($group['description']);

        return $group;
    }

    private function merge(array &$rows,array $book): void
    {
        $key=self::workKey($book['title']);
        foreach ($rows as &$row) {
            $numbered=$book['volume']!==null && $row['volume']!==null;
            $sameVolume=$numbered && (float)$book['volume']===(float)$row['volume'];
            if ($numbered && !$sameVolume) { continue; }
            $sameReference=$book['reference'] && $book['reference']===$row['reference'];
            if (!$sameVolume && !$sameReference && ($key==='' || $key!==self::workKey($row['title']))) { continue; }
            $row['owned']=$row['owned']||$book['owned'];
            $row['read']=$row['read']||$book['read'];
            $row['volume']??=$book['volume'];
            if ($row['genre']==='') { $row['genre']=$book['genre']; }
            $row['upcoming']=$row['upcoming']&&$book['upcoming'];
            if ($row['date']==='' || ($book['date']!=='' && $book['date']<$row['date'])) { $row['date']=$book['date']; }
            $row['book_id']??=$book['book_id'];
            $row['suggestion_id']??=$book['suggestion_id'];
            $row['reference']??=$book['reference'];
            return;
        }
        unset($row);
        $rows[]=$book;
    }
}
