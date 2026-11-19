<?php
namespace App\Twig;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
final class ReadingExtension extends AbstractExtension {
 public function getFilters(): array { return [new TwigFilter('progress_label',[$this,'label']),new TwigFilter('genre_label',[\App\Service\GenreLabels::class,'format'])]; }
 public function label(mixed $value,string $unit): string {
  $n=(float)$value;$number=rtrim(rtrim(number_format($n,2,',',' '),'0'),',');
  if($unit==='minutes') { $minutes=(int)round($n);return ($minutes>=60?intdiv($minutes,60).' h ':'').($minutes%60 || $minutes<60?($minutes%60).' min':''); }
  return $number.match($unit){'percent'=>' %','chapters'=>' chap.','location'=>' empl.',default=>' p.'};
 }
}
