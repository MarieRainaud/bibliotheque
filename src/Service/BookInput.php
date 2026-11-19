<?php
namespace App\Service;
final class BookInput
{
    public const FORMATS=['paper'=>'Papier','digital'=>'Numérique','audio'=>'Audio'];
    public const UNITS=['pages'=>'Pages','minutes'=>'Minutes','chapters'=>'Chapitres','location'=>'Emplacement'];
    public const DEFAULT_UNITS=['paper'=>'pages','digital'=>'pages','audio'=>'minutes'];
    public const POSITION_LABELS=['pages'=>'Page atteinte','minutes'=>'Minutes écoutées','chapters'=>'Chapitre atteint','location'=>'Emplacement atteint'];
    public const TOTAL_LABELS=['pages'=>'Nombre de pages','minutes'=>'Durée totale en minutes','chapters'=>'Nombre de chapitres','location'=>'Dernier emplacement'];
    public const STATUSES=['to_read'=>'À lire','reading'=>'En cours','finished'=>'Terminés'];
    public static function text(mixed $value,int $max): string { return mb_substr(trim(is_scalar($value)?(string)$value:''),0,$max); }
    public static function number(mixed $value,?float $max=null): ?float
    {
        if ($value===null || $value==='') { return null; }
        $value=str_replace(',','.',(string)$value);
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value<0 || (float)$value>($max??9999999)) { throw new \InvalidArgumentException('Le repère de progression doit être un nombre positif valide.'); }
        return round((float)$value,2);
    }
    public static function date(mixed $value): ?string
    {
        if (!$value) { return null; }
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)$value);
        if (!$date || $date->format('Y-m-d')!==$value) { throw new \InvalidArgumentException('La date indiquée n’est pas valide.'); }
        return $date->format('Y-m-d');
    }
    public static function book(array $data): array
    {
        $title=self::text($data['title']??'',250);
        if (!$title) { throw new \InvalidArgumentException('Le titre du livre est nécessaire.'); }
        $format=$data['format']??'paper';$unit=$data['unit']??(self::DEFAULT_UNITS[$format]??'pages');
        if (!isset(self::FORMATS[$format],self::UNITS[$unit])) { throw new \InvalidArgumentException('Le format ou le repère de progression n’est pas valide.'); }
        $total=self::number($data['total']??null);
        if ($total!==null && $total<=0) { throw new \InvalidArgumentException('Le total doit être supérieur à zéro, ou laissé vide.'); }
        $isbn=preg_replace('/[\s-]/u','',self::text($data['isbn']??'',30));
        if ($isbn && !preg_match('/^(?:\d{13}|\d{9}[\dXx])$/D',$isbn)) { throw new \InvalidArgumentException('L’ISBN doit contenir 10 ou 13 caractères, ou être laissé vide.'); }
        $google=self::text($data['google_id']??'',100);
        if ($google && !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$google)) { throw new \InvalidArgumentException('La référence du livre n’est pas valide.'); }
        return ['title'=>$title,'subtitle'=>self::text($data['subtitle']??'',250),'page_count'=>self::number($data['page_count']??null),'release_date'=>self::text($data['release_date']??($data['release']??''),30),'preorder'=>empty($data['preorder'])?0:1,'authors'=>self::text($data['authors']??'',500),'description'=>self::text($data['description']??'',15000),'isbn'=>$isbn,'google_id'=>$google?:null,'publisher'=>self::text($data['publisher']??'',200),'published'=>self::text($data['published']??'',30),'language'=>self::text($data['language']??'fr',8),'genre'=>self::text($data['genre']??'',120),'format'=>$format,'unit'=>$unit,'total'=>$total,'owned'=>empty($data['owned'])?0:1,'series_name'=>self::text($data['series_name']??'',200),'series_author'=>self::text(($data['series_author']??'')?: ($data['authors']??''),200),'volume'=>self::number($data['volume']??null,9999)];
    }
    public static function reading(array $data,array $book): array
    {
        $status=$data['status']??'to_read';
        if (!isset(self::STATUSES[$status])) { throw new \InvalidArgumentException('Le statut de lecture n’est pas valide.'); }
        $position=self::number($data['position']??0)??0;
        if ($book['total']!==null && $position>(float)$book['total']) { throw new \InvalidArgumentException('La progression dépasse le total du livre.'); }
        $started=self::date($data['started']??null);$finished=self::date($data['finished']??null);
        $today=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        if ($status==='reading' && !$started) { $started=$today; }
        if ($status==='finished') { $finished??=$today;if ($book['total']!==null) { $position=(float)$book['total']; } }
        else { $finished=null; }
        if ($started && $finished && $finished<$started) { throw new \InvalidArgumentException('La fin de lecture doit suivre son début.'); }
        $rating=self::number($data['rating']??null,5);
        if ($rating!==null && ($rating<1 || floor($rating)!=$rating)) { throw new \InvalidArgumentException('La note doit être comprise entre 1 et 5.'); }
        return ['status'=>$status,'position'=>$position,'started'=>$started,'finished'=>$finished,'rating'=>$rating,'notes'=>self::text($data['notes']??'',20000),'review'=>self::text($data['review']??'',10000)];
    }
}
