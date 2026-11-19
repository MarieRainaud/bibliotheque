<?php
namespace App\Service;

final class GenreLabels
{
    private const LABELS=[
        'fiction'=>'Roman','juvenile fiction'=>'Roman jeunesse','young adult fiction'=>'Roman pour adolescents',
        'fantasy'=>'Fantastique','fantasy magic'=>'Fantastique','wizards witches'=>'Magie',
        'action adventure'=>'Aventure','adventure'=>'Aventure','science fiction'=>'Science-fiction',
        'mystery detective'=>'Policier','thrillers'=>'Thriller','crime'=>'Policier','romance'=>'Romance',
        'historical'=>'Historique','horror'=>'Horreur','literary'=>'Littérature','humor'=>'Humour',
        'comics graphic novels'=>'Bande dessinée','graphic novels'=>'Bande dessinée',
        'biography autobiography'=>'Biographie','history'=>'Histoire','travel'=>'Voyage','drama'=>'Théâtre',
        'school education'=>'École','education'=>'Éducation','poetry'=>'Poésie',
        'roman'=>'Roman','roman jeunesse'=>'Roman jeunesse','imaginaire'=>'Imaginaire','fantastique'=>'Fantastique',
        'aventure'=>'Aventure','policier'=>'Policier','voyage'=>'Voyage','theatre'=>'Théâtre','histoire'=>'Histoire',
    ];

    public static function format(string $genre,bool $catalogue=false): string
    {
        if (!$catalogue) { return trim($genre); }
        $labels=[];
        foreach (preg_split('~[/,;]+~u',$genre) as $branch) {
            $label=self::LABELS[Discovery::normalize($branch)]??null;
            if ($label) { $labels[$label]=true; }
        }
        if (count($labels)>1) { unset($labels['Roman']); }
        if (isset($labels['Roman jeunesse'])) { unset($labels['Roman pour adolescents']); }
        $priority=['Fantastique','Science-fiction','Policier','Thriller','Romance','Historique','Horreur','Aventure','Roman jeunesse','Roman pour adolescents'];
        $ordered=array_values(array_unique([...array_intersect($priority,array_keys($labels)),...array_keys($labels)]));
        return implode(' · ',array_slice($ordered,0,3));
    }
}
