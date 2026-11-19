<?php
namespace App\EventSubscriber;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
final class ResponseHeaders implements EventSubscriberInterface {
 public function __construct(private string $issuer) {}
 public static function getSubscribedEvents(): array {return ['kernel.response'=>'headers'];}
 public function headers(ResponseEvent $event): void {
  $headers=$event->getResponse()->headers;
  $headers->set('X-Content-Type-Options','nosniff');$headers->set('Referrer-Policy','strict-origin-when-cross-origin');$headers->set('X-Frame-Options','SAMEORIGIN');
  $headers->set('Cache-Control','no-store, private');
  $origin=parse_url($this->issuer);$accountOrigin=($origin['scheme']??'https').'://'.($origin['host']??'').(isset($origin['port'])?':'.$origin['port']:'');
  $headers->set('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: https://books.google.com https://books.googleusercontent.com; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'none'; form-action 'self' ".$accountOrigin."; object-src 'none'");
  $headers->set('Permissions-Policy','camera=(), microphone=(), geolocation=()');
 }
}
