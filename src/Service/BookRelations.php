<?php
namespace App\Service;

final class BookRelations
{
    public function __construct(private Library $library,private GoogleBooks $catalogue) {}

    public function find(string $owner,array $book): array
    {
        $author=trim(explode(',',$book['authors'])[0]);
        $genre=trim(explode(',',$book['genre'])[0]);
        $seen=[SeriesCatalogue::workKey($book['title'])=>true];
        $sameAuthor=[];$similar=[];
        $local=$this->library->books($owner);
        foreach ($local as $other) {
            if (!GoogleBooks::isFrench($other['language']) || isset($seen[SeriesCatalogue::workKey($other['title'])])) { continue; }
            if ($author!=='' && SeriesCatalogue::authorMatches($author,$other['authors'])) {
                $this->append($sameAuthor,$seen,$other,'/livre/'.$other['id']);
            } elseif ($genre!=='' && Discovery::normalize(trim(explode(',',$other['genre'])[0]))===Discovery::normalize($genre)) {
                $this->append($similar,$seen,$other,'/livre/'.$other['id']);
            }
        }
        if (!str_starts_with($owner,'demo:') || !empty($book['google_id'])) {
            if ($author!=='' && count($sameAuthor)<6) {
                foreach ($this->search('inauthor:'.SeriesCatalogue::term($author)) as $other) {
                    if (SeriesCatalogue::authorMatches($author,$other['authors'])) {
                        $this->append($sameAuthor,$seen,$other,$this->link($other));
                    }
                }
            }
            if ($genre!=='' && count($similar)<6) {
                foreach ($this->search($this->subjectQuery($book,$genre)) as $other) {
                    if (!SeriesCatalogue::authorMatches($author,$other['authors'])) {
                        $this->append($similar,$seen,$other,$this->link($other));
                    }
                }
            }
        }
        return ['author'=>$sameAuthor,'similar'=>$similar];
    }

    private function subjectQuery(array $book,string $genre): string
    {
        $label=Discovery::normalize($genre);
        $keywords=Discovery::normalize($book['title'].' '.($book['description']??''));
        if (preg_match('/\b(?:fantasy|fantastique|magie|magique|sorcier|sorciers|sorciere|sorcieres)\b/',$label.' '.$keywords)) {
            return (str_contains($label,'juvenile')?'roman jeunesse ':'roman ').'magie fantastique';
        }
        $prefix=match (true) {
            str_contains($label,'juvenile fiction')=>'roman jeunesse',
            str_contains($label,'young adult')=>'roman adolescent',
            str_contains($label,'mystery') || str_contains($label,'thriller')=>'roman policier',
            str_contains($label,'science fiction')=>'roman science fiction',
            default=>'livre',
        };
        return $prefix.' subject:'.SeriesCatalogue::term($genre);
    }

    private function search(string $query): array
    {
        try { return $this->catalogue->search($query,0,false,false,'fr',20)['items']; }
        catch (\Throwable) { return []; }
    }

    private function link(array $book): string
    {
        return $book['google_id']!=='' ? '/ajouter?reference='.rawurlencode($book['google_id']) : '';
    }

    private function append(array &$result,array &$seen,array $book,string $href): void
    {
        $key=SeriesCatalogue::workKey($book['title']);
        if ($key==='' || $href==='' || count($result)>=6 || isset($seen[$key]) || !GoogleBooks::isFrench($book['language'])) { return; }
        $seen[$key]=true;
        $result[]=['title'=>$book['title'],'authors'=>$book['authors'],'href'=>$href,'reference'=>$book['google_id']??null];
    }
}
