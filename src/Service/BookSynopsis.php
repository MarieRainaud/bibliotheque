<?php
namespace App\Service;

final class BookSynopsis
{
    public static function text(string $description): string
    {
        $description=html_entity_decode($description,ENT_QUOTES|ENT_HTML5,'UTF-8');
        $description=preg_replace('~</(?:p|div|li)>|<br\s*/?>~i',"\n\n",$description);
        return trim(strip_tags($description));
    }

    public static function french(string $description,int $minimum=100): string
    {
        $description=self::text($description);
        if (mb_strlen($description)<$minimum || !self::looksFrench($description)) { return ''; }
        $paragraphs=preg_split('/\n\s*\n/u',$description,-1,PREG_SPLIT_NO_EMPTY);
        $paragraphs=array_filter($paragraphs,static fn(string $p): bool => mb_strlen($p)<90 || self::looksFrench($p));
        return trim(implode("\n\n",$paragraphs));
    }

    public static function best(array $editions): string
    {
        $best='';
        foreach ($editions as $edition) {
            $candidate=self::french($edition['description']??'');
            if (mb_strlen($candidate)>mb_strlen($best)) { $best=$candidate; }
        }
        return $best;
    }

    public static function excerpt(string $description): string
    {
        $flat=trim(preg_replace('/\s+/u',' ',$description));
        if (mb_strlen($flat)<=330) { return $flat; }
        return rtrim(preg_replace('/\s+\S*$/u','',mb_substr($flat,0,330))," ,;:").'…';
    }

    private static function looksFrench(string $description): bool
    {
        $words=array_count_values(explode(' ',Discovery::normalize($description)));
        $french=['les','des','une','dans','elle','elles','avec','pour','qui','aux','cette','ces','leurs','mais','sur','par','est','sont','lui','ses','dont','entre','alors','encore','comme','jusqu','qu'];
        $foreign=['the','and','with','this','that','from','when','who','their','his','her','have','will','into','but','they','this','el','los','una','del','las','sus','una','der','die','das','und','sich','mit','nicht','che','della','nella'];
        $count=static fn(array $markers): int => array_sum(array_intersect_key($words,array_flip($markers)));
        $fr=$count($french);$other=$count($foreign);
        return count(array_intersect_key($words,array_flip($french)))>=3 && $fr>=4 && $fr>=2*$other;
    }
}
