<?php
require dirname(__DIR__).'/bootstrap.php';
use App\Service\Account;
use Firebase\JWT\JWT;
use Symfony\Component\HttpFoundation\{Request,RequestStack};
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
$passed=0;
function expect(bool $ok,string $label):void {global $passed;if(!$ok){throw new RuntimeException($label);}echo 'OK '.$label.PHP_EOL;$passed++;}
function denied(callable $fn):bool {try{$fn();return false;}catch(Throwable){return true;}}
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'config'=>__DIR__.'/openssl.cnf']);openssl_pkey_export($key,$pem,null,['config'=>__DIR__.'/openssl.cnf']);$details=openssl_pkey_get_details($key);
$base64=fn($v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
$jwk=['keys'=>[['kty'=>'RSA','kid'=>'test-key','alg'=>'RS256','use'=>'sig','n'=>$base64($details['rsa']['n']),'e'=>$base64($details['rsa']['e'])]]];
class TestAccount extends Account {
 public array $keys=[];public array $tokens=[];
 protected function http(string $url,?array $form=null):array {return str_ends_with($url,'/certs')?$this->keys:$this->tokens;}
}
$stack=new RequestStack();$request=Request::create('/');$session=new Session(new MockArraySessionStorage());$request->setSession($session);$stack->push($request);
$db=new App\Service\Database($_SERVER['DB_HOST'],(int)$_SERVER['DB_PORT'],$_SERVER['DB_NAME'],$_SERVER['DB_USER'],$_SERVER['DB_PASSWORD']);
$account=new TestAccount($stack,$db,'https://accounts.example/realms/comptes','bibliotheque','local-secret','https://library.example');$account->keys=$jwk;
$claims=['iss'=>'https://accounts.example/realms/comptes','aud'=>'bibliotheque','azp'=>'bibliotheque','sub'=>'fixture-user','iat'=>time(),'exp'=>time()+3600,'nonce'=>'nonce'];
$sign=fn($c)=>JWT::encode($c,$pem,'RS256','test-key');
expect($account->verify($sign($claims))['sub']==='fixture-user','Accepter un jeton signé pour la bibliothèque');
expect(denied(fn()=>$account->verify($sign(array_replace($claims,['iss'=>'https://other.example'])))),'Refuser un autre émetteur');
expect(denied(fn()=>$account->verify($sign(array_replace($claims,['aud'=>'autre-application'])))),'Refuser un jeton destiné à une autre application');
expect(denied(fn()=>$account->verify($sign(array_replace($claims,['exp'=>time()-60])))),'Refuser un jeton expiré');
expect(denied(fn()=>$account->checkCsrf('wrong')),'Refuser un formulaire sans jeton CSRF');
$csrf=$account->csrf();$account->checkCsrf($csrf);expect(strlen($csrf)===64,'Valider le jeton CSRF de la session');
$url=$account->loginUrl();parse_str(parse_url($url,PHP_URL_QUERY),$params);expect($params['client_id']==='bibliotheque'&&$params['code_challenge_method']==='S256','Demander une connexion OIDC avec PKCE');
$flow=$session->get('oidc_flow');$account->tokens=['id_token'=>$sign(array_replace($claims,['nonce'=>$flow['nonce']])),'access_token'=>'fake','refresh_token'=>'refresh'];
expect(denied(fn()=>$account->callback('incorrect','code')),'Refuser un retour de connexion avec un état incorrect');
$account->loginUrl();$flow=$session->get('oidc_flow');$account->tokens['id_token']=$sign(array_replace($claims,['nonce'=>'wrong']));
expect(denied(fn()=>$account->callback($flow['state'],'code')),'Refuser un nonce incorrect');
$account->loginUrl();$flow=$session->get('oidc_flow');$account->tokens['id_token']=$sign(array_replace($claims,['nonce'=>$flow['nonce']]));
$account->callback($flow['state'],'code');expect($session->get('account')['sub']==='fixture-user','Établir la session après validation complète');
expect(denied(fn()=>$account->callback($flow['state'],'code')),'Refuser le rejeu du retour de connexion');
$url=$account->logoutUrl();parse_str(parse_url($url,PHP_URL_QUERY),$logout);
expect(isset($logout['id_token_hint'])&&$logout['post_logout_redirect_uri']==='https://library.example/','Transmettre la déconnexion centrale en un clic');
expect(!$session->has('account'),'Fermer la session locale');
echo $passed.' tests OIDC réussis.'.PHP_EOL;
