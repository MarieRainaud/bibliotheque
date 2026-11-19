<?php
namespace App\Controller;
use App\Service\Account;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
final class AuthController extends AbstractController {
 #[Route('/connexion',name:'login',methods:['GET'])]
 public function login(Request $request,Account $account): Response {
  try{return $this->redirect($account->loginUrl($request->query->getString('screen') === 'register'));}catch(\Throwable){$this->addFlash('error','La connexion aux comptes de l’atelier est momentanément indisponible.');return $this->redirectToRoute('home');}
 }
 #[Route('/auth/callback',name:'auth_callback',methods:['GET'])]
 public function callback(Request $request,Account $account): Response {
  try{$account->callback($request->query->getString('state'),$request->query->getString('code'));$request->getSession()->remove('demo_owner');}
  catch(\Throwable){$this->addFlash('error','La connexion n’a pas abouti. Une nouvelle tentative est nécessaire.');}
  return $this->redirectToRoute('home');
 }
 #[Route('/deconnexion',name:'logout',methods:['POST'])]
 public function logout(Request $request,Account $account): Response {$account->checkCsrf($request->request->get('_token'));return $this->redirect($account->logoutUrl());}
 #[Route('/auth/backchannel-logout',name:'backchannel_logout',methods:['POST'])]
 public function backchannel(Request $request,Account $account): Response {
  try{$account->backchannel($request->request->getString('logout_token'));return new Response('',200,['Cache-Control'=>'no-store']);}catch(\Throwable){return new Response('',400,['Cache-Control'=>'no-store']);}
 }
}
