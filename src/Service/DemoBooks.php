<?php
namespace App\Service;

final class DemoBooks
{
    private const DETAILS=[
        'La cartographe des brumes'=>['Élise Beaumont','Éditions des Marées','2024-04-12',360,
            'Sur les îles suspendues, les cartes ne restent jamais longtemps exactes. La brume efface les routes et déplace les rivages, au point que certains villages disparaissent des cartes sans quitter leur île. Léonie, apprentie cartographe, découvre dans le carnet de son père une route qu’aucun navigateur n’ose plus emprunter. Elle embarque pour relever les repères du voyage. Mais à mesure que sa carte se précise, elle comprend que quelqu’un a pris soin de faire disparaître les chemins qui mènent à son village.'],
        'Le jardin des heures'=>['Maya Delcourt','Éditions des Tilleuls','2025-03-07',280,
            'À la mort de sa grand-mère, Inès revient dans la maison où elle passait ses étés. Elle pensait vendre le jardin, mais découvre que les voisins y entretiennent encore un potager et s’y retrouvent chaque dimanche. Dans la remise, un calendrier annoté lui révèle des rendez-vous dont personne ne veut parler. En reprenant les travaux au fil des saisons, Inès apprend à connaître ceux qui ont gardé le jardin vivant et découvre ce que sa grand-mère leur avait confié.'],
        'Les îles suspendues - La cité du vent'=>['Élise Beaumont','Éditions des Marées','2025-06-06',410,
            'Léonie arrive dans une cité qui ne tient que par les cordages tendus entre ses tours. Le vent y tourne sans prévenir et les habitants se préparent à détacher tout un quartier avant la prochaine tempête. Lorsque le plan des ancrages disparaît, la cartographe doit retrouver les anciens points d’attache avec l’aide d’un jeune réparateur. Leurs relevés les conduisent sous la cité, où une autre carte attend depuis des années.'],
        'Sur les chemins du silence'=>['Noé Laurent','Éditions du Passage','2024-09-13',null,
            'Noé part à pied pour rejoindre le village où il a grandi. Il veut prendre les chemins que son père suivait autrefois, quitte à renoncer aux étapes qu’il avait préparées. Dans les refuges et les hameaux qu’il traverse, les rencontres lui donnent d’autres raisons de marcher. Le récit suit les détours du voyage, les gestes simples des personnes qui l’accueillent et les souvenirs que les paysages font remonter.'],
        'Une saison pour soi'=>['Camille Aubert','Éditions des Tilleuls','2025-02-21',248,
            'Claire accepte de tenir la librairie d’une amie pendant l’hiver. Elle s’installe dans une petite ville où elle ne connaît personne et se promet de ne rester que le temps nécessaire. Au fil des commandes et des conversations au comptoir, elle apprend à reconnaître les habitudes du quartier. Quand le retour de son amie approche, Claire doit décider si elle est prête à reprendre sa vie d’avant ou à faire une place à ce qu’elle a construit ici.'],
    ];

    public static function seriesDescription(string $owner,string $name,string $author): string
    {
        if (!str_starts_with($owner,'demo:') || $name!=='Les îles suspendues' || $author!=='Élise Beaumont') { return ''; }
        return 'Léonie, apprentie cartographe, parcourt un archipel où la brume et les vents déplacent les routes. D’île en île, elle apprend à lire les paysages et à faire confiance à ceux qui l’habitent. Ses cartes lui permettent de retrouver des chemins oubliés et de comprendre ce qui menace les communautés qu’elle rencontre.';
    }

    public static function fill(array $book): array
    {
        $details=self::DETAILS[$book['title']]??null;
        if (!$details || !str_starts_with($book['owner']??'','demo:') || !empty($book['google_id']) || $book['authors']!==$details[0]) {
            return $book;
        }
        foreach (array_combine(['publisher','published','page_count','description'],array_slice($details,1)) as $field=>$value) {
            if (empty($book[$field])) { $book[$field]=$value; }
        }
        return $book;
    }
}
