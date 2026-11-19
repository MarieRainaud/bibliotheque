<?php
require dirname(__DIR__).'/bootstrap.php';
use App\Service\{BookInput,Database,Library,GoogleBooks,Discovery};
$passed=0;$failed=0;
function check(string $name,callable $fn): void {global $passed,$failed;try{$fn();$passed++;echo "OK ".$name.PHP_EOL;}catch(\Throwable $e){$failed++;echo "ÉCHEC ".$name." - ".$e->getMessage().PHP_EOL;}}
function same(mixed $expected,mixed $actual): void {if($expected!==$actual){throw new \RuntimeException('Valeur inattendue - '.json_encode($actual,JSON_UNESCAPED_UNICODE));}}
function rejects(callable $fn): void {try{$fn();}catch(\InvalidArgumentException|Symfony\Component\HttpKernel\Exception\NotFoundHttpException){return;}throw new \RuntimeException('Le refus attendu n’a pas eu lieu.');}
check('Un total inconnu reste vide',fn()=>same(null,BookInput::book(['title'=>'Livre'])['total']));
check('Le numérique utilise les pages par défaut',fn()=>same('pages',BookInput::book(['title'=>'Livre','format'=>'digital','total'=>200])['unit']));
check('L’audio utilise les minutes par défaut',fn()=>same('minutes',BookInput::book(['title'=>'Livre','format'=>'audio'])['unit']));
check('Le pourcentage ne peut plus être choisi comme repère',fn()=>rejects(fn()=>BookInput::book(['title'=>'Livre','unit'=>'percent'])));
check('Les nombres décimaux français sont acceptés',fn()=>same(12.5,BookInput::number('12,5')));
check('Une progression négative est refusée',fn()=>rejects(fn()=>BookInput::number('-1')));
check('Une progression infinie est refusée',fn()=>rejects(fn()=>BookInput::number('1e999')));
check('Une date impossible est refusée',fn()=>rejects(fn()=>BookInput::date('2026-02-30')));
check('Un ISBN incorrect est refusé',fn()=>rejects(fn()=>BookInput::book(['title'=>'Livre','isbn'=>'abc'])));
check('Une progression supérieure au total est refusée',fn()=>rejects(fn()=>BookInput::reading(['position'=>101],['total'=>100])));
check('Une note hors échelle est refusée',fn()=>rejects(fn()=>BookInput::reading(['rating'=>6],['total'=>100])));
check('Une fin antérieure au début est refusée',fn()=>rejects(fn()=>BookInput::reading(['status'=>'finished','started'=>'2026-06-03','finished'=>'2026-06-02'],['total'=>100])));
check('Terminer une lecture atteint le total',fn()=>same(100.0,BookInput::reading(['status'=>'finished'],['total'=>100])['position']));
$series=['name'=>'Les îles suspendues','author'=>'Élise Beaumont','language'=>'fr'];
$item=['google_id'=>'new-id','isbn'=>'9781234567890','title'=>'Les îles suspendues - Tome 3','authors'=>'Élise Beaumont','subtitle'=>'','language'=>'fr','published'=>'2027'];
check('Un nouveau tome est proposé',fn()=>same(3.0,Discovery::candidate($series,$item,[])['volume']));
check('Une autre autrice est exclue',fn()=>same(null,Discovery::candidate($series,array_replace($item,['authors'=>'Une Autrice']),[])));
check('Une autre langue est exclue',fn()=>same(null,Discovery::candidate($series,array_replace($item,['language'=>'en']),[])));
$known=[['google_id'=>'old','isbn'=>'','volume'=>3,'title'=>'Une autre édition']];
check('Un tome connu dans une autre édition est exclu',fn()=>same(null,Discovery::candidate($series,$item,$known)));
check('Un coffret est exclu',fn()=>same(null,Discovery::candidate($series,array_replace($item,['title'=>'Les îles suspendues - Coffret 1 à 3']),[])));
check('Un numéro annoncé dans le titre est reconnu',fn()=>same(2.0,App\Service\SeriesCatalogue::volume(['title'=>'Les heures - Tome n° 2','authors'=>'Maya Delcourt'])));
check('Un ordinal explicite dans le résumé est reconnu',fn()=>same(4.0,App\Service\SeriesCatalogue::volume(['title'=>'Les heures','authors'=>'Maya Delcourt','description'=>'Dans ce quatrième tome, les personnages se retrouvent.'])));
check('Une mention d’un ancien tome ne donne pas de numéro au livre',fn()=>same(null,App\Service\SeriesCatalogue::volume(['title'=>'Les heures','authors'=>'Maya Delcourt','description'=>'Elle se souvient de ce qui est arrivé dans le premier tome.'])));
check('Un numéro inconnu reste à vérifier',fn()=>same(null,Discovery::candidate($series,array_replace($item,['title'=>'Les îles suspendues - La traversée']),[])['volume']));
check('La normalisation conserve les équivalences françaises',fn()=>same('elise et le coeur',Discovery::normalize('Élise et le cœur')));
$db=new Database($_SERVER['DB_HOST'],(int)$_SERVER['DB_PORT'],$_SERVER['DB_NAME'],$_SERVER['DB_USER'],$_SERVER['DB_PASSWORD']);$db->install();$library=new Library($db);
$owner='test:'.bin2hex(random_bytes(12));$other='test:'.bin2hex(random_bytes(12));$id=null;
try{
 check('Créer un livre avec une lecture initiale',function()use($library,$owner,&$id){$id=$library->save($owner,['title'=>'Le jardin des heures','authors'=>'Maya Delcourt','total'=>200,'owned'=>1,'series_name'=>'Les heures','series_author'=>'Maya Delcourt','volume'=>1]);same(1,count($library->readings($owner,$id)));});
 check('Un autre compte ne voit pas le livre',fn()=>same([], $library->books($other)));
 check('Un autre compte ne peut pas ouvrir le livre',fn()=>rejects(fn()=>$library->book($other,$id)));
 check('Un autre compte ne peut pas supprimer le livre',fn()=>rejects(fn()=>$library->remove($other,$id)));
 check('La recherche accepte des mots indépendants',fn()=>same(1,count($library->books($owner,['q'=>'jardin delcourt']))));
 check('Les caractères SQL ne deviennent pas des jokers',fn()=>same(0,count($library->books($owner,['q'=>'%']))));
 check('La progression est conservée',function()use($library,$owner,$id){$library->updateReading($owner,$id,['status'=>'reading','position'=>40]);same(40.0,(float)$library->readings($owner,$id)[0]['position']);});
 check('Une relecture exige une lecture terminée',fn()=>rejects(fn()=>$library->reread($owner,$id)));
 check('La relecture conserve les notes précédentes',function()use($library,$owner,$id){$library->updateReading($owner,$id,['status'=>'finished','notes'=>'Note à conserver','review'=>'Un avis personnel','rating'=>5]);$library->reread($owner,$id);$reads=$library->readings($owner,$id);same(2,count($reads));same('Note à conserver',$reads[1]['notes']);same(0.0,(float)$reads[0]['position']);});
 check('Convertir un ancien pourcentage préserve la progression et ses repères',function()use($library,$owner){
  $legacyId=$library->save($owner,['title'=>'Ancien livre numérique','format'=>'digital','total'=>100,'status'=>'reading','position'=>25]);
  $library->updateReading($owner,$legacyId,['status'=>'reading','position'=>40]);
  $library->run("UPDATE book SET unit='percent' WHERE id=?",[$legacyId]);
  $library->updateReading($owner,$legacyId,['status'=>'reading','notes'=>'Notes conservées']);
  rejects(fn()=>$library->save($owner,['title'=>'Ancien livre numérique','format'=>'digital'],$legacyId));
  same('percent',$library->book($owner,$legacyId)['unit']);
  $library->save($owner,['title'=>'Ancien livre numérique','format'=>'digital','total'=>200],$legacyId);
  $read=$library->readings($owner,$legacyId)[0];
  same('pages',$library->book($owner,$legacyId)['unit']);
  same(80.0,(float)$read['position']);same('Notes conservées',$read['notes']);
  same([50.0,80.0],array_map('floatval',array_column($library->query('SELECT position FROM progress WHERE reading_id=? ORDER BY id',[$read['id']]),'position')));
 });
 check('Un identifiant Google ne peut pas être ajouté deux fois dans le même support',function()use($library,$owner){$library->save($owner,['title'=>'Un livre','google_id'=>'testgoogle']);rejects(fn()=>$library->save($owner,['title'=>'Un livre','google_id'=>'testgoogle']));});
 class FakeGoogle extends GoogleBooks {
  public array $items=[];public bool $fail=false;
  public function __construct(){}
  public function search(string $query,int $page=0,bool $newest=false,bool $force=false,string $language='',int $limit=10):array {if($this->fail){throw new \RuntimeException('Simulation indisponible');}return ['items'=>$this->items,'total'=>count($this->items)];}
 }
 $fake=new FakeGoogle();$fake->items=[$item];$discovery=new Discovery($library,$fake);
 $sid=$library->seriesId($owner,$series['name'],$series['author']);
 check('La découverte crée une proposition persistante',function()use($discovery,$library,$owner){do { $batch=$discovery->check($owner); } while ($batch['remaining']>0);same(1,count($library->suggestions($owner)));});
 check('Une proposition écartée ne réapparaît pas',function()use($library,$owner,$sid,$discovery){$s=$library->suggestions($owner)[0];$library->suggestionState($owner,(int)$s['id'],'dismissed');$library->run('UPDATE series SET attempted_at=NULL,checked_at=NULL WHERE id=?',[$sid]);$discovery->check($owner);same(0,count($library->suggestions($owner)));same(1,count($library->suggestions($owner,'dismissed')));});
 check('Une erreur API ne marque pas la série comme vérifiée',function()use($library,$owner,$sid,$discovery,$fake){$fake->fail=true;$library->run('UPDATE series SET attempted_at=NULL,checked_at=NULL WHERE id=?',[$sid]);$discovery->check($owner);$s=$library->query('SELECT checked_at,check_error FROM series WHERE id=?',[$sid])[0];same(null,$s['checked_at']);same(true,(bool)$s['check_error']);});
 check('Les propositions restent privées',fn()=>same([], $library->suggestions($other,'dismissed')));
 check('Une série compte les œuvres, les relectures et les sorties séparément',function()use($library,$owner,$id,$fake){
  $book=$library->book($owner,$id);
  $library->save($owner,['title'=>'Le jardin des heures (édition audio)','authors'=>'Maya Delcourt','series_name'=>'Les heures','series_author'=>'Maya Delcourt','volume'=>1,'format'=>'audio','owned'=>1]);
  $library->run('INSERT INTO suggestion(series_id,fingerprint,google_id,title,authors,published,language,volume,reason,preorder) VALUES(?,?,?,?,?,?,?,?,?,?)',[$book['series_id'],hash('sha256','test-annoncé'),'test-future','Les heures - Tome 2','Maya Delcourt','','fr',2,'',1]);
  $groups=(new App\Service\SeriesCatalogue($library,$fake))->dashboard($owner);
  $group=array_values(array_filter($groups,fn($s)=>$s['id']===$book['series_id']))[0];
  same(1,count($group['published_books']));same(1,$group['read_count']);same(true,$group['published_books'][0]['owned']);
  same(1,count($group['upcoming_books']));same('',$group['upcoming_books'][0]['date']);
 });
}finally{$library->run('DELETE FROM book WHERE owner IN (?,?)',[$owner,$other]);$library->run('DELETE FROM series WHERE owner IN (?,?)',[$owner,$other]);}
echo PHP_EOL.$passed.' tests réussis, '.$failed.' échec(s).'.PHP_EOL;exit($failed?1:0);
