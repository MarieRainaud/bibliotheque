<?php
require dirname(__DIR__).'/bootstrap.php';
$base=$_SERVER['APP_URL'];if(!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost'],true)){throw new RuntimeException('Les tests HTTP s’exécutent uniquement en local.');}
$client=curl_init();curl_setopt_array($client,[CURLOPT_COOKIEFILE=>'',CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>15]);
function http(string $path,?array $data=null):array {global $client,$base;curl_setopt_array($client,[CURLOPT_URL=>$base.$path,CURLOPT_HTTPGET=>true,CURLOPT_POST=>false]);if($data!==null){curl_setopt_array($client,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);}return ['body'=>curl_exec($client),'status'=>curl_getinfo($client,CURLINFO_RESPONSE_CODE),'url'=>curl_getinfo($client,CURLINFO_EFFECTIVE_URL)];}
function token(string $html):string {preg_match('/name="_token" value="([^"]+)"/',$html,$m);return $m[1]??'';}
$count=0;function ok(bool $value,string $name):void {global $count;if(!$value){throw new RuntimeException($name);}echo 'OK '.$name.PHP_EOL;$count++;}
$home=http('/');ok($home['status']===200&&str_contains($home['body'],'action="/demonstration"'),'Accueil accessible');
$noAuth=http('/ajouter');ok($noAuth['status']===403,'Bibliothèque inaccessible sans session');
$csrf=token($home['body']);$bad=http('/demonstration',['_token'=>'wrong']);ok($bad['status']===403,'Un formulaire sans CSRF est refusé');
$demo=http('/demonstration',['_token'=>$csrf]);ok($demo['status']===200&&str_contains($demo['body'],'La cartographe des brumes'),'Démonstration isolée accessible');
ok(str_contains($demo['body'],'<h1>Ma bibliothèque</h1>'),'La collection est nommée Ma bibliothèque');
$csrf=token($demo['body']);
foreach(['/recherche','/series','/nouveautes'] as $path){ok(http($path)['status']===200,'Écran '.$path);}
$title='Vérification '.bin2hex(random_bytes(5));
$new=http('/ajouter',['_token'=>$csrf,'title'=>$title,'authors'=>'Autrice de test','format'=>'digital','unit'=>'pages','total'=>200,'owned'=>1,'status'=>'reading','position'=>50,'series_name'=>'','series_author'=>'','language'=>'fr']);
ok($new['status']===200&&str_contains($new['body'],$title),'Ajout de livre depuis un formulaire');
ok(str_contains($new['body'],'Page atteinte')&&str_contains($new['body'],'50 p. / 200 p. · 25 %'),'La saisie en pages affiche le pourcentage calculé');
preg_match('~/livre/(\d+)~',$new['url'],$m);$id=(int)($m[1]??0);ok($id>0,'Redirection vers la nouvelle fiche');
$db=new App\Service\Database($_SERVER['DB_HOST'],(int)$_SERVER['DB_PORT'],$_SERVER['DB_NAME'],$_SERVER['DB_USER'],$_SERVER['DB_PASSWORD']);$lib=new App\Service\Library($db);$testOwner=null;
try{
 $testOwner=$lib->query('SELECT owner FROM book WHERE id=?',[$id])[0]['owner']??null;ok(str_starts_with($testOwner??'','demo:'),'Les données de démonstration ne sont pas privées');
 $csrf=token($new['body']);
 $readingsPage=http('/lectures?status=finished');
 ok($readingsPage['status']===200&&parse_url($readingsPage['url'],PHP_URL_PATH)==='/'&&parse_url($readingsPage['url'],PHP_URL_QUERY)==='status=finished'&&!str_contains($readingsPage['body'],'href="/lectures"'),'L’ancienne adresse Lectures renvoie vers Ma bibliothèque avec ses filtres');
 $form=http('/recherche');
 ok(str_contains($form['body'],'name="title" type="search"')&&str_contains($form['body'],'name="author" type="search"')&&str_contains($form['body'],'name="isbn" type="search"')&&!str_contains($form['body'],'name="language"')&&!str_contains($form['body'],'Google Books'),'Trois critères distincts pour la recherche');
 ok(http('/api/recherche?isbn=abc')['status']===422,'Un ISBN incorrect est signalé');
 $ref='test-'.bin2hex(random_bytes(5));$query='Catalogue '.$ref;
 $seriesName='Les Feuilles d’été '.$ref;
 $fr=['id'=>$ref,'volumeInfo'=>['title'=>$seriesName.' - Tome 1','subtitle'=>'Un sous-titre','authors'=>['Auteur du catalogue'],'description'=>'This is an English synopsis of the story. The main character travels with her friends and discovers the secret that they have kept from her.','publisher'=>'Éditions du test','publishedDate'=>'2024-02-01','language'=>'fr','pageCount'=>240,'industryIdentifiers'=>[['type'=>'ISBN_13','identifier'=>'9781234567897']]]];
 $fr['volumeInfo']['categories']=['Juvenile Fiction / Action & Adventure / General','Fiction / Fantasy / Contemporary','Young Adult Fiction / Fantasy / Wizards & Witches'];
 $frAlt=$fr;$frAlt['id']=$ref.'-alt';$frAlt['volumeInfo']['title'].=' (édition illustrée)';$frAlt['volumeInfo']['description']='Résumé fourni par le catalogue. Dans ce roman, une jeune libraire découvre les carnets de sa grand-mère et décide de suivre les pistes qu’elle a laissées au fil des années. Avec ses amis, elle remonte jusqu’aux villages où sa famille a vécu pour comprendre ce qui les a séparés.';$frAlt['volumeInfo']['industryIdentifiers'][0]['identifier']='9781234567898';
 $frNext=$fr;$frNext['id']=$ref.'-next';$frNext['volumeInfo']['title']=$seriesName.' - Tome 2';$frNext['volumeInfo']['industryIdentifiers'][0]['identifier']='9781234567899';
 $en=['id'=>$ref.'-en','volumeInfo'=>['title'=>'English edition','language'=>'en']];
 $request=['q'=>$query,'maxResults'=>10,'startIndex'=>0,'printType'=>'books','langRestrict'=>'fr'];
 $editionsQuery=['q'=>'intitle:'.App\Service\SeriesCatalogue::term($fr['volumeInfo']['title']).' inauthor:"Auteur du catalogue"','maxResults'=>40,'startIndex'=>0,'printType'=>'books','langRestrict'=>'fr'];
 $seriesQuery=App\Service\SeriesCatalogue::query($seriesName,'Auteur du catalogue');
 $seriesParams=['q'=>$seriesQuery,'maxResults'=>40,'startIndex'=>0,'printType'=>'books','langRestrict'=>'fr'];
 $nextParams=['q'=>$seriesQuery,'maxResults'=>20,'startIndex'=>0,'printType'=>'books','orderBy'=>'newest','showPreorders'=>'true','langRestrict'=>'fr'];
 $catalogueFixtures=[
  ['/volumes',$seriesParams,['totalItems'=>2,'items'=>[$fr,$frNext]]],
  ['/volumes',$nextParams,['totalItems'=>0,'items'=>[]]],
  ['/volumes',$request,['totalItems'=>14,'items'=>[$fr,$frAlt,$frNext,$en]]],
  ['/volumes',array_replace($request,['startIndex'=>10]),['totalItems'=>14,'items'=>[$frAlt]]],
  ['/volumes',$editionsQuery,['totalItems'=>3,'items'=>[$fr,$frAlt,$frNext]]],
  ['/volumes/'.$ref,[],$fr],
  ['/volumes/'.$ref.'-alt',[],$frAlt],
  ['/volumes/'.$ref.'-next',[],$frNext],
  ['/volumes/'.$ref.'-en',[],$en],
 ];
 $fixtureKeys=[];
 foreach($catalogueFixtures as [$endpoint,$params,$payload]){
  $key=hash('sha256',$endpoint.json_encode($params));$fixtureKeys[]=$key;
  $lib->run('INSERT INTO api_cache(cache_key,payload,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))',[$key,json_encode($payload,JSON_THROW_ON_ERROR)]);
 }
 $search=http('/api/recherche?q='.urlencode($query).'&language=en');
 $results=json_decode($search['body'],true,512,JSON_THROW_ON_ERROR);
 foreach($results['items'] as $item){$fixtureKeys[]=hash('sha256','catalogue-editions:'.$item['work']);}
 ok($search['status']===200&&array_column($results['items'],'language')===['fr','fr'],'Le serveur exclut les éditions non francophones');
 ok(array_column($results['items'],'google_id')===[$ref,$ref.'-next']&&!array_key_exists('isbn',$results['items'][0]),'Une carte par livre, et les tomes restent distincts');
 ok(str_contains($results['items'][0]['excerpt'],'Résumé fourni par le catalogue.')&&!str_contains($results['items'][0]['excerpt'],'This is an English synopsis'),'Le résultat reprend le résumé français d’une autre édition');
 $later=json_decode(http('/api/recherche?q='.urlencode($query).'&page=1')['body'],true,512,JSON_THROW_ON_ERROR);
 ok($later['items'][0]['work']===$results['items'][0]['work'],'Une édition de la page suivante a le même identifiant de livre');
 $editions=json_decode(http('/api/editions?reference='.$ref)['body'],true,512,JSON_THROW_ON_ERROR);
 ok(array_column($editions['editions'],'isbn')===['9781234567897','9781234567898'],'Seules les éditions du même livre sont sur sa fiche');
 ok(http('/ajouter?reference='.$ref.'-en')['status']===422,'Une édition non francophone ne peut pas être ajoutée');
 $preview=http('/ajouter?reference='.$ref);
 ok($preview['status']===200&&str_contains($preview['body'],'Un sous-titre')&&str_contains($preview['body'],'Résumé fourni par le catalogue.')&&str_contains($preview['body'],'240 p.')&&str_contains($preview['body'],'9781234567897')&&!str_contains($preview['body'],'This is an English synopsis')&&!str_contains($preview['body'],'Nouvelle recherche')&&str_contains($preview['body'],'Fantastique · Aventure · Roman jeunesse')&&!str_contains($preview['body'],'Juvenile Fiction')&&!str_contains($preview['body'],'name="title"')&&str_contains($preview['body'],'data-editions-url'),'La fiche du catalogue est renseignée et consultable sans formulaire de métadonnées');
 ok(str_contains($preview['body'],'name="follow_series"')&&str_contains($preview['body'],'suivre ses nouveautés'),'Le suivi est proposé sans saisie du nom de la série');
 ok(str_contains($preview['body'],'href="/series/catalogue?reference='.$ref.'">Voir la série</a>'),'La fiche du catalogue donne accès à la série non suivie');
 $seriesCount=count($lib->series($testOwner));
 $catalogueSeries=http('/series/catalogue?reference='.$ref);
 ok($catalogueSeries['status']===200&&str_contains($catalogueSeries['body'],$seriesName.' - Tome 1')&&str_contains($catalogueSeries['body'],$seriesName.' - Tome 2')&&count($lib->series($testOwner))===$seriesCount,'La page de série non suivie présente ses deux tomes sans activer le suivi');
 $notInLibrary=http('/api/etat-livre',['_token'=>$csrf,'reference'=>$ref,'flag'=>'owned','value'=>'1']);
 $newFlags=json_decode($notInLibrary['body'],true,512,JSON_THROW_ON_ERROR);
 $unchangedSeries=http('/series/catalogue?reference='.$ref.'&fragment=1');
 ok($notInLibrary['status']===200&&$newFlags['owned']&&!$newFlags['read']&&count($lib->series($testOwner))===$seriesCount&&str_contains($unchangedSeries['body'],'data-book-id="'.$newFlags['id'].'"')&&str_contains($unchangedSeries['body'],$seriesName.' - Tome 2'),'Cocher un tome l’ajoute sans activer le suivi ni vider le tableau');
 $lib->remove($testOwner,$newFlags['id']);
 ok(http('/ajouter?reference='.$ref.'-next&rapide=1',['_token'=>'incorrect'])['status']===403,'L’ajout direct exige un formulaire authentique');
 $quick=http('/ajouter?reference='.$ref.'-next&rapide=1',['_token'=>$csrf]);
 $quickData=json_decode($quick['body'],true,512,JSON_THROW_ON_ERROR);
 ok($quick['status']===201&&str_starts_with($quickData['url'],'/livre/')&&$quickData['series']['name']===$seriesName,'Ajout direct avec proposition de suivi sans passer par la fiche');
 $quickId=(int)basename($quickData['url']);$quickBook=$lib->book($testOwner,$quickId);
 ok(!$quickBook['owned']&&!$quickBook['ever_read'],'L’ajout direct laisse Possédé et Lu décochés');
 $follow=http($quickData['series']['url'],['_token'=>$csrf]);
 ok($follow['status']===200&&json_decode($follow['body'],true,512,JSON_THROW_ON_ERROR)['name']===$seriesName,'La série proposée après un ajout direct peut être suivie');
 $added=http('/ajouter?reference='.$ref,['_token'=>$csrf,'title'=>'Titre falsifié','follow_series'=>1]);
 preg_match('~/livre/(\d+)~',$added['url'],$addedId);$catalogueId=(int)($addedId[1]??0);
 ok($added['status']===200&&$catalogueId>0&&str_contains($added['body'],$seriesName.' - Tome 1')&&str_contains($added['body'],'Résumé fourni par le catalogue.'),'L’ajout conserve les données du catalogue sans saisie');
 $again=http('/ajouter?reference='.$ref.'-alt&rapide=1',['_token'=>$csrf]);
 $againData=json_decode($again['body'],true,512,JSON_THROW_ON_ERROR);
 ok($again['status']===200&&$againData['existing']&&$againData['url']==='/livre/'.$catalogueId,'L’ajout direct d’une autre édition ne crée pas un doublon');
 $seriesPage=http('/series');
 $seriesId=(int)$lib->book($testOwner,$catalogueId)['series_id'];
 ok($seriesPage['status']===200&&str_contains($seriesPage['body'],'href="/series/'.$seriesId.'"')&&str_contains($seriesPage['body'],$seriesName)&&!str_contains($seriesPage['body'],'class="series-table"'),'Séries est une liste de liens sans tableau');
 ok(str_contains($added['body'],'href="/series/'.$seriesId.'">Voir la série</a>'),'La fiche du livre ouvre directement la page de sa série');
 $seriesDetail=http('/series/'.$seriesId);
 ok($seriesDetail['status']===200&&str_contains($seriesDetail['body'],'<h1>'.$seriesName.'</h1>')&&str_contains($seriesDetail['body'],'<th scope="col">Possédé</th>')&&str_contains($seriesDetail['body'],'<th scope="col">Lu</th>')&&str_contains($seriesDetail['body'],'À paraître')&&!str_contains($seriesDetail['body'],'Les îles suspendues')&&str_contains($seriesDetail['body'],$seriesName.' - Tome 2'),'Une page par série contient son tableau et uniquement ses livres');
 ok(str_contains($seriesDetail['body'],'data-book-flag="owned"')&&str_contains($seriesDetail['body'],'data-book-flag="read"'),'Le tableau utilise des cases à cocher');
 $fromTable=http('/api/etat-livre',['_token'=>$csrf,'id'=>$catalogueId,'flag'=>'read','value'=>'1']);
 $flagState=json_decode($fromTable['body'],true,512,JSON_THROW_ON_ERROR);
 ok($fromTable['status']===200&&$flagState['read']&&!$flagState['owned']&&$flagState['status']==='finished','Cocher Lu termine la lecture sans modifier Possédé');
 $ownedChange=http('/api/etat-livre',['_token'=>$csrf,'id'=>$catalogueId,'flag'=>'owned','value'=>'1']);
 $bookAfterFlags=http('/livre/'.$catalogueId);
 ok($ownedChange['status']===200&&str_contains($bookAfterFlags['body'],'data-book-flag="owned" checked')&&str_contains($bookAfterFlags['body'],'data-book-flag="read" checked'),'Les cases du tableau modifient la fiche du même livre');
 $undone=http('/api/etat-livre',['_token'=>$csrf,'id'=>$catalogueId,'flag'=>'read','value'=>'0']);
 $undoState=json_decode($undone['body'],true,512,JSON_THROW_ON_ERROR);
 ok($undone['status']===200&&!$undoState['read']&&$undoState['owned']&&$undoState['status']==='to_read','Décocher Lu rétablit le statut sans retirer la possession');
 $seriesFragment=http('/series/'.$seriesId.'?fragment=1');
 ok($seriesFragment['status']===200&&!str_contains($seriesFragment['body'],'<!doctype html>')&&str_contains($seriesFragment['body'],'Livres parus'),'Actualisation du tableau de la série sans changer de page');
 $demoSeries=$lib->books($testOwner,['q'=>'cartographe'])[0]['series_id'];
 $demoSeriesDetail=http('/series/'.$demoSeries);
 foreach([$seriesPage['body'],$demoSeriesDetail['body']] as $screen){
  ok(str_contains($screen,'<progress class="series-owned" max="2" value="1"')&&str_contains($screen,'<progress class="series-read" max="2" value="0"'),'La progression distingue tomes possédés et lus sur les deux écrans');
 }
 ok(str_contains($seriesPage['body'],'/images/books/cartographe-des-brumes.svg')&&str_contains($seriesPage['body'],'Imaginaire')&&str_contains($seriesPage['body'],'parcourt un archipel')&&!str_contains($seriesPage['body'],'Les jardins d’écume'),'L’aperçu montre le premier tome, le genre et le résumé général');
 $oldNews=http('/nouveautes');
 ok(parse_url($oldNews['url'],PHP_URL_PATH)==='/series','L’ancien écran des nouveautés redirige vers Séries');
 $seeded=$lib->books($testOwner,['q'=>'cartographe'])[0];
 $demoBook=http('/livre/'.$seeded['id']);
 ok($demoBook['status']===200&&str_contains($demoBook['body'],'<dl class="book-metadata">')&&str_contains($demoBook['body'],'Éditions des Marées')&&str_contains($demoBook['body'],'Léonie, apprentie cartographe')&&str_contains($demoBook['body'],'Lecture actuelle')&&str_contains($demoBook['body'],'href="/series/'.$seeded['series_id'].'">Voir la série</a>'),'La fiche de démonstration affiche les informations et le résumé avant le suivi');
 $related=http('/api/livres-associes?id='.$seeded['id']);
 $relatedItems=json_decode($related['body'],true,512,JSON_THROW_ON_ERROR);
 ok($related['status']===200&&in_array('Les îles suspendues - La cité du vent',array_column($relatedItems['author'],'title'),true),'Autres livres du même auteur reliés à la fiche');
 $settings=http('/livre/'.$catalogueId.'/modifier');
 ok($settings['status']===200&&!str_contains($settings['body'],'name="title"')&&!str_contains($settings['body'],'<textarea id="description"'),'Modifier ne rend pas les métadonnées éditables');
 $edited=http('/livre/'.$catalogueId.'/modifier',['_token'=>$csrf,'format'=>'digital','unit'=>'pages','total'=>240,'owned'=>1,'title'=>'Titre falsifié']);
 ok($edited['status']===200&&str_contains($edited['body'],$seriesName.' - Tome 1')&&!str_contains($edited['body'],'Titre falsifié'),'Modifier conserve les métadonnées et enregistre le support personnel');
 $updated=http('/livre/'.$id.'/lecture',['_token'=>$csrf,'status'=>'finished','position'=>200,'rating'=>4,'notes'=>'Note HTTP','review'=>'Avis HTTP']);
 ok($updated['status']===200&&str_contains($updated['body'],'Avis HTTP'),'Progression et avis enregistrés');
 $reread=http('/livre/'.$id.'/relire',['_token'=>$csrf]);ok($reread['status']===200&&str_contains($reread['body'],'Avis HTTP')&&str_contains($reread['body'],'Une nouvelle lecture commence'),'Relecture avec conservation de l’historique');
 $undoReread=http('/api/etat-livre',['_token'=>$csrf,'id'=>$id,'flag'=>'read','value'=>'0']);
 $older=$lib->readings($testOwner,$id)[1];
 ok($undoReread['status']===200&&!$lib->book($testOwner,$id)['ever_read']&&$older['status']==='finished'&&$older['review']==='Avis HTTP','Les lectures précédentes restent dans l’historique');
 $filtered=http('/?q='.urlencode($title));ok(str_contains($filtered['body'],$title),'Recherche depuis la bibliothèque');
 $outside=http('/?q=harry+potter');
 ok($outside['status']===200&&str_contains($outside['body'],'Aucun livre trouvé dans ma bibliothèque')&&!str_contains($outside['body'],'data-google-search'),'Ma bibliothèque filtre uniquement les livres enregistrés, même sans correspondance');
 ok(str_contains($form['body'],'<h1>Rechercher un livre</h1>')&&str_contains($form['body'],'data-google-search')&&!str_contains($form['body'],'id="library-filters"'),'La recherche de nouveaux livres a sa propre page');
 http('/?q='.urlencode($title));
 $detail=http('/livre/'.$id);ok(str_contains($detail['body'],'q='.urlencode($title)),'Retour conservant la recherche');
 ok(!str_contains($detail['body'],'←')&&str_contains($detail['body'],'Retirer ce livre de ma bibliothèque'),'Retour sans flèche et retrait clairement nommé');
 $export=http('/exporter');$data=json_decode($export['body'],true,512,JSON_THROW_ON_ERROR);ok($export['status']===200&&count($data['books'])>=1,'Export JSON de la bibliothèque');
 $deleted=http('/livre/'.$id.'/supprimer',['_token'=>$csrf]);ok($deleted['status']===200,'Suppression depuis un formulaire protégé');
 $missing=http('/livre/'.$id);ok($missing['status']===404,'Une fiche supprimée n’est plus accessible');
 $exit=http('/demonstration/quitter',['_token'=>$csrf]);ok($exit['status']===200&&str_contains($exit['body'],'action="/demonstration"'),'Sortie de démonstration');
 curl_setopt($client,CURLOPT_FOLLOWLOCATION,false);$login=http('/connexion');ok($login['status']===302,'Redirection vers les comptes partagés');
 $headers=[];curl_setopt($client,CURLOPT_HEADERFUNCTION,function($curl,$header)use(&$headers){$headers[]=$header;return strlen($header);});http('/connexion');$joined=implode('',$headers);ok(str_contains($joined,'client_id=bibliotheque')&&str_contains($joined,'code_challenge_method=S256')&&str_contains($joined,'8098/realms/comptes'),'Client partagé et PKCE présents dans la demande de connexion');
}finally{foreach($fixtureKeys??[] as $key){$lib->run('DELETE FROM api_cache WHERE cache_key=?',[$key]);}if($testOwner&&str_starts_with($testOwner,'demo:')){$lib->run('DELETE FROM book WHERE owner=?',[$testOwner]);$lib->run('DELETE FROM series WHERE owner=?',[$testOwner]);}}
echo $count.' tests HTTP réussis.'.PHP_EOL;
