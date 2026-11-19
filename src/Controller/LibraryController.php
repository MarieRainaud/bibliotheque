<?php
namespace App\Controller;
use App\Service\{Account,Library,GoogleBooks,Discovery,BookInput,SeriesCatalogue,BookRelations,CatalogueWorks,DemoBooks};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse};
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
final class LibraryController extends AbstractController
{
 public function __construct(private Account $account,private Library $library,private GoogleBooks $google,private Discovery $discovery,private SeriesCatalogue $seriesCatalogue,private BookRelations $bookRelations,private CatalogueWorks $works,private string $portfolioUrl,private string $atelierUrl) {}
 private function owner(Request $r,bool $required=true): ?string {
  $u=$this->account->user();if($u){return 'private:'.hash('sha256',$u['iss'].'|'.$u['sub']);}
  $demo=$r->getSession()->get('demo_owner');if($demo){return $demo;}
  if($required){throw new AccessDeniedHttpException('Une connexion ou une session de démonstration est nécessaire.');}return null;
 }
 private function page(Request $r,string $template,array $data=[],int $status=200): Response {
  $o=$this->owner($r,false);
  return $this->render($template,array_merge(['account'=>$this->account->user(),'demo'=>$o&&str_starts_with($o,'demo:'),'csrf'=>$this->account->csrf(),'portfolio_url'=>$this->portfolioUrl,'atelier_url'=>$this->atelierUrl,'notification_count'=>$o?$this->library->upcomingCount($o):0,'formats'=>BookInput::FORMATS,'units'=>BookInput::UNITS,'default_units'=>BookInput::DEFAULT_UNITS,'position_labels'=>BookInput::POSITION_LABELS,'total_labels'=>BookInput::TOTAL_LABELS,'statuses'=>BookInput::STATUSES],$data),new Response(status:$status));
 }
 #[Route('/',name:'home',methods:['GET'])]
 public function home(Request $r): Response {
  $o=$this->owner($r,false);if(!$o){return $this->page($r,'welcome.html.twig');}
  $f=[];foreach(['q','status','format','series','owned','sort'] as $k){$f[$k]=BookInput::text($r->query->get($k,''),200);}
  $all=$this->library->books($o);$stats=['to_read'=>0,'reading'=>0,'finished'=>0];foreach($all as $b){$stats[$b['status']]++;}
  $r->getSession()->set('library_return',$r->getRequestUri());
  return $this->page($r,'home.html.twig',['books'=>$this->library->books($o,$f),'filters'=>$f,'series'=>$this->library->series($o),'stats'=>$stats,'total_books'=>count($all)]);
 }
 #[Route('/lectures',name:'readings',methods:['GET'])]
 public function legacyReadings(Request $r): Response {
  return $this->redirectToRoute('home',array_intersect_key($r->query->all(),array_flip(['q','status','format','series','owned','sort'])));
 }
 #[Route('/demonstration',name:'demo',methods:['POST'])]
 public function demo(Request $r): Response {
  $this->account->checkCsrf($r->request->get('_token'));if($this->account->user()){return $this->redirectToRoute('home');}
  $o=$r->getSession()->get('demo_owner');if(!$o){$o='demo:'.bin2hex(random_bytes(24));$r->getSession()->set('demo_owner',$o);$this->library->seedDemo($o);}return $this->redirectToRoute('home');
 }
 #[Route('/demonstration/quitter',name:'demo_exit',methods:['POST'])]
 public function demoExit(Request $r): Response {$this->account->checkCsrf($r->request->get('_token'));$r->getSession()->remove('demo_owner');return $this->redirectToRoute('home');}
 #[Route('/recherche',name:'catalogue_search',methods:['GET'])]
 public function searchPage(Request $r): Response {
  $this->owner($r);
  return $this->page($r,'search.html.twig');
 }
 #[Route('/ajouter',name:'book_new',methods:['GET','POST'])]
 #[Route('/livre/{id}/modifier',name:'book_edit',requirements:['id'=>'\\d+'],methods:['GET','POST'])]
 public function edit(Request $r,?int $id=null): Response {
  $o=$this->owner($r);$error=null;
  if($id===null){
   $reference=$r->query->getString('reference',$r->query->getString('google'));
   if($reference!==''){return $this->catalogue($r,$o,$reference);}
   if($r->isMethod('GET')&&!$r->query->getBoolean('manuel')){
    return $this->redirectToRoute('catalogue_search');
   }
  }
  $b=$id?$this->library->book($o,$id):['title'=>'','authors'=>'','description'=>'','isbn'=>'','google_id'=>'','publisher'=>'','published'=>'','language'=>'fr','genre'=>'','format'=>'paper','unit'=>'pages','total'=>'','owned'=>0,'series_name'=>'','series_author'=>'','volume'=>'','status'=>'to_read','position'=>0,'started'=>'','finished'=>'','rating'=>'','notes'=>'','review'=>''];
  $lockedMetadata=$id!==null;
  $legacyPercent=$b['unit']==='percent';
  if($legacyPercent){$b['unit']=BookInput::DEFAULT_UNITS[$b['format']];$b['total']='';}
  if($r->isMethod('POST')){
   $this->account->checkCsrf($r->request->get('_token'));
   $fields=['format','unit','total'];
   if(!$lockedMetadata){$fields=[...$fields,'title','authors','description','isbn','publisher','published','genre'];}
   if($id===null){$fields=[...$fields,'status','position','started','finished'];}
   $b=array_replace($b,array_intersect_key($r->request->all(),array_flip($fields)));
   $b['owned']=$r->request->getBoolean('owned');
   if($id===null&&$r->request->getBoolean('read')){$b['status']='finished';}
   try{
    $saved=$this->library->save($o,$b,$id);
    $this->addFlash('success',$id?'Les réglages de lecture ont été enregistrés.':'Le livre a été ajouté à ma bibliothèque.');
    return $this->redirectToRoute('book_show',['id'=>$saved]);
   }catch(\InvalidArgumentException $e){$error=$e->getMessage();}
  }
  return $this->page($r,'edit.html.twig',['book'=>$b,'editing'=>$id!==null,'error'=>$error,'legacy_percent'=>$legacyPercent,'locked_metadata'=>$lockedMetadata],$error?422:200);
 }
 private function catalogue(Request $r,string $owner,string $reference): Response {
  $quick=$r->isMethod('POST')&&$r->query->getBoolean('rapide');
  if($r->isMethod('POST')){$this->account->checkCsrf($r->request->get('_token'));}
  $errorResponse=fn(string $message,int $status): Response => $quick
   ? $this->json(['error'=>$message],$status)
   : $this->page($r,'search.html.twig',['error'=>$message],$status);
  try{$book=$this->google->get($reference);}
  catch(\Throwable){return $errorResponse('La fiche est momentanément indisponible.',503);}
  if(!GoogleBooks::isFrench($book['language'])){
   return $errorResponse('Cette édition n’est pas disponible en français.',422);
  }
  if($quick){
   $key=CatalogueWorks::key($book);
   foreach($this->library->books($owner) as $existing){
    if(CatalogueWorks::key($existing)===$key){
     return $this->json(['url'=>$this->generateUrl('book_show',['id'=>$existing['id']]),'existing'=>true,'series'=>null]);
    }
   }
  }
  $sid=$r->query->getInt('suggestion');$error=null;
  $suggestion=$sid?$this->library->suggestion($owner,$sid):null;
  if($suggestion && $suggestion['google_id']!==$reference){throw $this->createNotFoundException();}
  $proposal=$suggestion?null:$this->seriesCatalogue->identify($book,$this->library->series($owner));
  $book['description']=$this->works->description($book,!$quick);
  if($r->isMethod('POST')){
   $book['owned']=!$quick&&$r->request->getBoolean('owned');
   $book['status']=!$quick&&$r->request->getBoolean('read')?'finished':'to_read';
   $book['follow_series']=false;
   if($suggestion){
    $book['series_name']=$suggestion['series_name'];$book['series_author']=$suggestion['series_author'];$book['volume']=$suggestion['volume'];
   }elseif($proposal && ($proposal['followed'] || $r->request->getBoolean('follow_series'))){
    $book['series_name']=$proposal['name'];$book['series_author']=$proposal['author'];$book['volume']=$proposal['volume'];$book['series_description']=$proposal['description']??'';$book['follow_series']=true;
   }
   try{
    $saved=$this->library->save($owner,$book);
    if($suggestion){$this->library->suggestionState($owner,$sid,'accepted');}
    if($quick){
     return $this->json([
      'url'=>$this->generateUrl('book_show',['id'=>$saved]),
      'existing'=>false,
      'series'=>$proposal&&!$book['follow_series']?['name'=>$proposal['name'],'url'=>$this->generateUrl('book_follow_series',['id'=>$saved])]:null,
     ],201);
    }
    $this->addFlash('success','Le livre a été ajouté à ma bibliothèque.');
    return $this->redirectToRoute('book_show',['id'=>$saved]);
   }catch(\InvalidArgumentException $e){if($quick){return $errorResponse($e->getMessage(),422);}$error=$e->getMessage();}
  }
  $series=$suggestion?['id'=>$suggestion['series_id'],'name'=>$suggestion['series_name'],'volume'=>$suggestion['volume']]:$proposal;
  return $this->page($r,'book.html.twig',['personal'=>false,'book'=>$book,'error'=>$error,'suggestion_id'=>$sid,'series_proposal'=>$proposal,'series_link'=>$this->seriesLink($series,$reference)],$error?422:200);
 }
 #[Route('/livre/{id}',name:'book_show',requirements:['id'=>'\\d+'],methods:['GET'])]
 public function show(Request $r,int $id): Response {
  $o=$this->owner($r);$b=DemoBooks::fill($this->library->book($o,$id));$reads=$this->library->readings($o,$id);$back=$r->getSession()->get('library_return','/');
  if(!preg_match('~^/(?:lectures)?(?:\\?.*)?$~D',$back)){$back='/';}
  $series=$b['series_id']?['id'=>$b['series_id'],'name'=>$b['series_name'],'volume'=>$b['volume']??SeriesCatalogue::volume($b)]:null;
  if(!$series && $b['google_id']){
   try{$edition=$this->google->get($b['google_id']);}catch(\Throwable){$edition=$b;}
   $series=$this->seriesCatalogue->identify($edition,$this->library->series($o));
  }
  if($b['google_id']){$b['description']=$this->works->description($b);}
  return $this->page($r,'book.html.twig',['personal'=>true,'book'=>$b,'series_link'=>$this->seriesLink($series,$b['google_id']??''),'readings'=>$reads,'current'=>$reads[0],'progress'=>$this->library->query('SELECT position,recorded_at FROM progress WHERE reading_id=? ORDER BY id DESC LIMIT 8',[$reads[0]['id']]),'return_url'=>$back]);
 }
 #[Route('/livre/{id}/lecture',name:'reading_update',requirements:['id'=>'\\d+'],methods:['POST'])]
 public function progress(Request $r,int $id): Response {
  $this->account->checkCsrf($r->request->get('_token'));
  try{$this->library->updateReading($this->owner($r),$id,$r->request->all());$this->addFlash('success','La lecture a été enregistrée.');}
  catch(\InvalidArgumentException $e){$this->addFlash('error',$e->getMessage());}return $this->redirectToRoute('book_show',['id'=>$id]);
 }
 #[Route('/livre/{id}/relire',name:'reread',requirements:['id'=>'\\d+'],methods:['POST'])]
 public function reread(Request $r,int $id): Response {
  $this->account->checkCsrf($r->request->get('_token'));
  try{$this->library->reread($this->owner($r),$id);$this->addFlash('success','Une nouvelle lecture commence. La précédente reste dans l’historique.');}
  catch(\InvalidArgumentException $e){$this->addFlash('error',$e->getMessage());}return $this->redirectToRoute('book_show',['id'=>$id]);
 }
 #[Route('/livre/{id}/supprimer',name:'book_delete',requirements:['id'=>'\\d+'],methods:['POST'])]
 public function delete(Request $r,int $id): Response {$this->account->checkCsrf($r->request->get('_token'));$this->library->remove($this->owner($r),$id);$this->addFlash('success','Le livre a été retiré de ma bibliothèque.');return $this->redirectToRoute('home');}
 private function seriesLink(?array $series,string $reference): ?array {
  if(!$series){return null;}
  $series['url']=!empty($series['id'])
   ? $this->generateUrl('series_show',['id'=>$series['id']])
   : $this->generateUrl('series_catalogue',['reference'=>$reference]);
  return $series;
 }
 #[Route('/series',name:'series',methods:['GET','POST'])]
 public function series(Request $r): Response {
  $owner=$this->owner($r);
  if($r->isMethod('POST')){
   $this->account->checkCsrf($r->request->get('_token'));
   $id=$r->request->getInt('id');
   if(!$this->library->query('SELECT id FROM series WHERE id=? AND owner=?',[$id,$owner])){throw $this->createNotFoundException();}
   $follow=$r->request->getBoolean('followed')?1:0;
   $this->library->run('UPDATE series SET checked_at=IF(followed=0 AND ?=1,NULL,checked_at),attempted_at=IF(followed=0 AND ?=1,NULL,attempted_at),followed=? WHERE id=? AND owner=?',[$follow,$follow,$follow,$id,$owner]);
   return $this->redirectToRoute('series_show',['id'=>$id],303);
  }
  $groups=$this->seriesCatalogue->dashboard($owner);
  if($r->query->getBoolean('fragment')){return $this->render('_series_cards.html.twig',['series'=>$groups]);}
  return $this->page($r,'series.html.twig',['series'=>$groups]);
 }
 #[Route('/series/{id}',name:'series_show',requirements:['id'=>'\\d+'],methods:['GET'])]
 public function showSeries(Request $r,int $id): Response {
  $group=$this->seriesCatalogue->dashboard($this->owner($r),$id)[0]??null;
  if(!$group){throw $this->createNotFoundException();}
  if($r->query->getBoolean('fragment')){return $this->render('_series_tables.html.twig',['group'=>$group]);}
  return $this->page($r,'series_detail.html.twig',['group'=>$group,'book_url'=>null,'fragment_url'=>$this->generateUrl('series_show',['id'=>$id,'fragment'=>1])]);
 }
 #[Route('/series/catalogue',name:'series_catalogue',methods:['GET'])]
 public function catalogueSeries(Request $r): Response {
  $owner=$this->owner($r);
  $reference=$r->query->getString('reference');
  try{$book=$this->google->get($reference);}
  catch(\Throwable){throw $this->createNotFoundException('Série indisponible.');}
  if(!GoogleBooks::isFrench($book['language'])){throw $this->createNotFoundException();}
  $series=$this->seriesCatalogue->identify($book,$this->library->series($owner));
  if(!$series){throw $this->createNotFoundException('Série non identifiée.');}
  $fragment=$r->query->getBoolean('fragment');
  if(!empty($series['id'])){return $this->redirectToRoute('series_show',['id'=>$series['id']]+($fragment?['fragment'=>1]:[]));}
  $group=$this->seriesCatalogue->preview($owner,$series,$book);
  if($fragment){return $this->render('_series_tables.html.twig',['group'=>$group]);}
  return $this->page($r,'series_detail.html.twig',[
   'group'=>$group,'book_url'=>$this->generateUrl('book_new',['reference'=>$reference]),
   'fragment_url'=>$this->generateUrl('series_catalogue',['reference'=>$reference,'fragment'=>1]),
  ]);
 }
 #[Route('/nouveautes',name:'news',methods:['GET'])]
 public function news(Request $r): Response {$this->owner($r);return $this->redirectToRoute('series');}
 #[Route('/nouveautes/{id}',name:'suggestion_action',requirements:['id'=>'\\d+'],methods:['POST'])]
 public function suggestionAction(Request $r,int $id): Response {$this->account->checkCsrf($r->request->get('_token'));$this->library->suggestionState($this->owner($r),$id,$r->request->get('action')==='restore'?'pending':'dismissed');return $this->redirectToRoute('series');}
 #[Route('/api/livres-associes',name:'book_relations',methods:['GET'])]
 public function relatedBooks(Request $r): JsonResponse {
  $owner=$this->owner($r);$id=$r->query->getInt('id');
  if($id){$book=$this->library->book($owner,$id);}
  else{
   try{$book=$this->google->get($r->query->getString('reference'));}
   catch(\Throwable){return $this->json(['author'=>[],'similar'=>[]]);}
   if(!GoogleBooks::isFrench($book['language'])){return $this->json(['author'=>[],'similar'=>[]]);}
  }
  $r->getSession()->save();
  return $this->json($this->bookRelations->find($owner,$book));
 }
 #[Route('/api/etat-livre',name:'book_flag',methods:['POST'])]
 public function bookFlag(Request $r): JsonResponse {
  $owner=$this->owner($r);
  $this->account->checkCsrf($r->request->get('_token'));
  $flag=$r->request->getString('flag');
  $value=$r->request->getString('value');
  if(!in_array($flag,['owned','read'],true)||!in_array($value,['0','1'],true)){return $this->json(['error'=>'État du livre non valide.'],422);}
  $id=$r->request->getInt('id');
  try{
   if(!$id){
    if($value!=='1'){return $this->json(['error'=>'Ce tome ne figure pas dans ma bibliothèque.'],422);}
    $reference=$r->request->getString('reference');
    $sid=$r->request->getInt('suggestion');
    $suggestion=$sid?$this->library->suggestion($owner,$sid):null;
    if($suggestion&&$suggestion['google_id']!==$reference){throw $this->createNotFoundException();}
    try{$edition=$this->google->get($reference);}
    catch(\Throwable){return $this->json(['error'=>'La fiche du tome est momentanément indisponible.'],503);}
    if(!GoogleBooks::isFrench($edition['language'])){return $this->json(['error'=>'Cette édition n’est pas disponible en français.'],422);}
    $key=CatalogueWorks::key($edition);
    foreach($this->library->books($owner) as $existing){
     if(CatalogueWorks::key($existing)===$key){$id=(int)$existing['id'];break;}
    }
    if(!$id){
     $series=$suggestion?['name'=>$suggestion['series_name'],'author'=>$suggestion['series_author'],'volume'=>$suggestion['volume']]:$this->seriesCatalogue->identify($edition,$this->library->series($owner));
     if($suggestion||!empty($series['id'])){
      $edition['series_name']=$series['name'];$edition['series_author']=$series['author'];$edition['volume']=$series['volume'];
      $edition['series_description']=$series['description']??'';
     }
     $edition['follow_series']=false;
     $edition['description']=$this->works->description($edition,false);
     $edition['owned']=$flag==='owned';$edition['status']=$flag==='read'?'finished':'to_read';
     $id=$this->library->save($owner,$edition);
     if($suggestion){$this->library->suggestionState($owner,$sid,'accepted');}
    }
   }
   $this->library->setFlag($owner,$id,$flag,$value==='1');
  }catch(\InvalidArgumentException $e){return $this->json(['error'=>$e->getMessage()],422);}
  $book=$this->library->book($owner,$id);$current=$this->library->readings($owner,$id)[0];
  return $this->json([
   'id'=>$id,'owned'=>(bool)$book['owned'],'read'=>(bool)$book['ever_read'],
   'status'=>$current['status'],'position'=>$current['position'],'started'=>$current['started'],'finished'=>$current['finished'],
   'summary'=>$this->renderView('_reading_summary.html.twig',['book'=>$book,'current'=>$current,'statuses'=>BookInput::STATUSES]),
   'series_url'=>$book['series_id']?$this->generateUrl('series_show',['id'=>$book['series_id']]):null,
  ]);
 }
 #[Route('/livre/{id}/suivre-serie',name:'book_follow_series',requirements:['id'=>'\\d+'],methods:['POST'])]
 public function followBookSeries(Request $r,int $id): JsonResponse {
  $owner=$this->owner($r);
  $this->account->checkCsrf($r->request->get('_token'));
  $book=$this->library->book($owner,$id);
  try{$edition=$this->google->get($book['google_id']??'');}
  catch(\Throwable){return $this->json(['error'=>'La série est momentanément indisponible.'],503);}
  $proposal=$this->seriesCatalogue->identify($edition,$this->library->series($owner));
  if(!$proposal){return $this->json(['error'=>'Aucune série n’a pu être identifiée.'],422);}
  $this->library->followBookSeries($owner,$id,$proposal);
  return $this->json(['name'=>$proposal['name']]);
 }
 #[Route('/api/editions',name:'catalogue_editions',methods:['GET'])]
 public function editions(Request $r): JsonResponse {
  $this->owner($r);
  try{$book=$this->google->get($r->query->getString('reference'));}
  catch(\Throwable){return $this->json(['error'=>'Les éditions sont momentanément indisponibles.'],503);}
  if(!GoogleBooks::isFrench($book['language'])){return $this->json(['editions'=>[]]);}
  $r->getSession()->save();
  return $this->json(['editions'=>$this->works->editions($book)]);
 }
 #[Route('/api/recherche',name:'google_search',methods:['GET'])]
 public function search(Request $r): JsonResponse {
  $owner=$this->owner($r);
  $title=BookInput::text($r->query->get('title'),200);
  $author=BookInput::text($r->query->get('author'),200);
  $isbn=BookInput::text($r->query->get('isbn'),30);
  $terms=[];
  if($title!==''){$terms[]='intitle:'.SeriesCatalogue::term($title);}
  if($author!==''){$terms[]='inauthor:'.SeriesCatalogue::term($author);}
  if($isbn!==''){
   $isbn=strtoupper(preg_replace('/[\s\p{Pd}]/u','',$isbn));
   if(!preg_match('/^(?:\d{13}|\d{9}[\dX])$/D',$isbn)){return $this->json(['error'=>'Saisir un ISBN de 10 ou 13 caractères.'],422);}
   $terms[]='isbn:'.$isbn;
  }
  $q=$terms?implode(' ',$terms):BookInput::text($r->query->get('q'),200);
  if(mb_strlen($q)<2){return $this->json(['items'=>[],'more'=>false]);}
  $r->getSession()->save();
  try{return $this->json($this->works->results($q,$r->query->getInt('page'),$this->library->books($owner)));}
  catch(\Throwable){return $this->json(['error'=>'La recherche de livres est momentanément indisponible.'],503);}
 }
 #[Route('/api/nouveautes',name:'discovery',methods:['POST'])]
 public function discover(Request $r): JsonResponse {
  $this->account->checkCsrf($r->headers->get('X-CSRF-Token'));$o=$this->owner($r);$r->getSession()->save();
  try{return $this->json($this->discovery->check($o,$r->request->getBoolean('force')));}
  catch(\Throwable){return $this->json(['error'=>'La recherche des nouveautés est momentanément indisponible.'],503);}
 }
 #[Route('/exporter',name:'export',methods:['GET'])]
 public function export(Request $r): Response {
  $o=$this->owner($r);$books=[];foreach($this->library->books($o) as $b){unset($b['owner']);$b['readings']=$this->library->readings($o,(int)$b['id']);$books[]=$b;}
  $series=$this->library->series($o);foreach($series as &$s){unset($s['owner']);}
  return new Response(json_encode(['version'=>1,'exported_at'=>gmdate('c'),'books'=>$books,'series'=>$series],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),200,['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="bibliotheque.json"','Cache-Control'=>'no-store']);
 }
}
