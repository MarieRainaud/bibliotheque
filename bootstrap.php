<?php
ini_set('display_errors','0');
require_once __DIR__.'/vendor/autoload.php';
$dotenv = new Symfony\Component\Dotenv\Dotenv();
$dotenv->bootEnv(is_file(__DIR__.'/.env') ? __DIR__.'/.env' : __DIR__.'/.env.example');
