<?php
namespace App\Service;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class Account
{

    public function __construct(private RequestStack $requests, private Database $database, private string $issuer, private string $clientId, private string $clientSecret, private string $appUrl) {}

    public function user(): ?array
    {
        $session=$this->requests->getSession(); $user=$session->get('account');
        if (!is_array($user)) { return null; }
        if (($user['exp'] ?? 0) <= time()+10) {
            try {
                $refresh=$session->get('oidc_refresh');
                if (!$refresh) { throw new \RuntimeException('Session expirée'); }
                $tokens=$this->http($this->issuer.'/protocol/openid-connect/token', ['grant_type'=>'refresh_token','refresh_token'=>$refresh,'client_id'=>$this->clientId,'client_secret'=>$this->clientSecret]);
                $user=$this->verify($tokens['id_token']);
                if ($user['sub'] !== $session->get('account')['sub']) { throw new \RuntimeException('Compte inattendu'); }
                $session->set('account',$user); $session->set('oidc_refresh',$tokens['refresh_token'] ?? $refresh); $session->set('oidc_id_token',$tokens['id_token']);
            } catch (\Throwable) { $session->invalidate(); return null; }
        }
        $query=$this->database->connection()->prepare('SELECT MAX(issued_at) FROM oidc_logout WHERE subject=? AND (sid IS NULL OR sid=?)');
        $query->execute([$user['sub'],$user['sid'] ?? '']);
        if ((int)$query->fetchColumn() >= (int)$session->get('login_at',PHP_INT_MAX)) { $session->invalidate(); return null; }
        return $user;
    }

    public function csrf(): string
    {
        $session=$this->requests->getSession();
        if (!$session->has('csrf')) { $session->set('csrf',bin2hex(random_bytes(32))); }
        return $session->get('csrf');
    }

    public function checkCsrf(mixed $token): void
    {
        $expected=$this->requests->getSession()->get('csrf','');
        if (!is_string($token) || !$expected || !hash_equals($expected,$token)) { throw new AccessDeniedHttpException('Le formulaire a expiré. Recharger la page avant de réessayer.'); }
    }

    public function loginUrl(bool $register = false): string
    {
        if (!$this->clientSecret) { throw new \RuntimeException('La connexion aux comptes de l’atelier reste à configurer.'); }
        $state=bin2hex(random_bytes(32)); $nonce=bin2hex(random_bytes(32)); $verifier=bin2hex(random_bytes(48));
        $this->requests->getSession()->set('oidc_flow',['state'=>$state,'nonce'=>$nonce,'verifier'=>$verifier,'created'=>time()]);
        return $this->issuer.'/protocol/openid-connect/'.($register ? 'registrations' : 'auth').'?'.http_build_query(['client_id'=>$this->clientId,'redirect_uri'=>$this->appUrl.'/auth/callback','response_type'=>'code','scope'=>'openid profile email','state'=>$state,'nonce'=>$nonce,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256']);
    }

    public function callback(string $state, string $code): void
    {
        $session=$this->requests->getSession(); $flow=$session->remove('oidc_flow');
        if (!$flow || !$state || !$code || time()-$flow['created']>600 || !hash_equals($flow['state'],$state)) { throw new AccessDeniedHttpException('La connexion a expiré. Une nouvelle connexion est nécessaire.'); }
        $tokens=$this->http($this->issuer.'/protocol/openid-connect/token',['grant_type'=>'authorization_code','code'=>$code,'client_id'=>$this->clientId,'client_secret'=>$this->clientSecret,'redirect_uri'=>$this->appUrl.'/auth/callback','code_verifier'=>$flow['verifier']]);
        $user=$this->verify($tokens['id_token'] ?? '');
        if (!hash_equals($flow['nonce'], $user['nonce'] ?? '')) { throw new AccessDeniedHttpException('Connexion non valide.'); }
        if (isset($user['at_hash']) && !hash_equals($user['at_hash'],rtrim(strtr(base64_encode(substr(hash('sha256',$tokens['access_token'] ?? '',true),0,16)),'+/','-_'),'='))) { throw new AccessDeniedHttpException('Jeton de connexion non valide.'); }
        $session->migrate(true); $session->remove('csrf'); $session->set('account',$user); $session->set('login_at',time());
        $session->set('oidc_refresh',$tokens['refresh_token'] ?? null); $session->set('oidc_id_token',$tokens['id_token']);
    }

    public function logoutUrl(): string
    {
        $session=$this->requests->getSession(); $idToken=$session->get('oidc_id_token'); $session->invalidate();
        return $idToken ? $this->issuer.'/protocol/openid-connect/logout?'.http_build_query(['id_token_hint'=>$idToken,'post_logout_redirect_uri'=>$this->appUrl.'/','client_id'=>$this->clientId]) : $this->appUrl.'/';
    }

    public function backchannel(string $token): void
    {
        $claims=$this->verify($token);
        if (!isset($claims['events']['http://schemas.openid.net/event/backchannel-logout']) || isset($claims['nonce']) || abs(time()-(int)$claims['iat'])>300) { throw new AccessDeniedHttpException('Déconnexion non valide.'); }
        $this->database->connection()->prepare('INSERT INTO oidc_logout (subject,sid,issued_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE issued_at=GREATEST(issued_at,VALUES(issued_at))')->execute([$claims['sub'],$claims['sid'] ?? null,$claims['iat']]);
    }

    public function verify(string $token): array
    {
        if (!$token || strlen($token)>24000) { throw new AccessDeniedHttpException('Jeton absent ou non valide.'); }
        $keys=$this->http($this->issuer.'/protocol/openid-connect/certs');
        $claims=(array)JWT::decode($token,JWK::parseKeySet($keys,'RS256'));
        $aud=is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? ''];
        if (($claims['iss'] ?? '') !== $this->issuer || !in_array($this->clientId,$aud,true) || (isset($claims['azp']) && $claims['azp'] !== $this->clientId) || empty($claims['sub']) || !isset($claims['iat'],$claims['exp']) || $claims['iat']>time()+30) { throw new AccessDeniedHttpException('Identité non valide.'); }
        return json_decode(json_encode($claims,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
    }

    protected function http(string $url, ?array $form = null): array
    {
        $parts=parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '',['127.0.0.1','localhost'],true))) { throw new \RuntimeException('Le service de comptes doit utiliser HTTPS.'); }
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
        if ($form !== null) { curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]); }
        $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); 
        if (!is_string($body) || $status !== 200) { throw new \RuntimeException('Le service de comptes est momentanément indisponible.'); }
        return json_decode($body,true,512,JSON_THROW_ON_ERROR);
    }
}
