<?php
namespace App\Service;
class GoogleBooks
{
    public function __construct(private Database $database,private string $apiKey) {}
    public function configured(): bool { return $this->apiKey!==''; }
    public function search(string $query,int $page=0,bool $newest=false,bool $force=false,string $language='',int $limit=10): array
    {
        $limit=max(1,min($limit,40));
        $params=['q'=>$query,'maxResults'=>$limit,'startIndex'=>max(0,min($page,40))*$limit,'printType'=>'books'];
        if ($newest) { $params['orderBy']='newest'; $params['showPreorders']='true'; }
        if ($language!=='') { $params['langRestrict']=$language; }
        $data=$this->request('/volumes',$params,$force?0:($newest?21600:3600));
        return ['total'=>(int)($data['totalItems']??0),'items'=>array_map([$this,'normalize'],$data['items']??[])];
    }
    public static function isFrench(string $language): bool
    {
        return preg_match('/^fr(?:[-_][a-z]+)?$/iD', $language) === 1;
    }
    public function searchFrench(string $query,int $page): array
    {
        $page=max(0,min($page,40));
        $result=$this->search($query,$page,false,false,'fr');
        return [
            'items'=>array_values(array_filter($result['items'],static fn(array $book): bool => self::isFrench($book['language']))),
            'more'=>$page<40 && count($result['items'])===10 && ($page+1)*10<$result['total'],
        ];
    }
    public function get(string $id): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$id)) { throw new \InvalidArgumentException('Référence de livre non valide.'); }
        return $this->normalize($this->request('/volumes/'.rawurlencode($id),[],86400));
    }
    public function normalize(array $item): array
    {
        $v=$item['volumeInfo']??[];$isbn='';$pages=(int)($v['pageCount']??0);
        foreach ($v['industryIdentifiers']??[] as $identifier) { if (($identifier['type']??'')==='ISBN_13') { $isbn=$identifier['identifier'];break; }if (($identifier['type']??'')==='ISBN_10') { $isbn=$identifier['identifier']; } }
        $series=$v['seriesInfo']['volumeSeries'][0]??[];
        $seriesName=$series['seriesTitle']??$v['seriesInfo']['seriesTitle']??'';
        $sale=$item['saleInfo']??[];
        $preorder=($sale['saleability']??'')==='FOR_PREORDER';
        $published=$v['publishedDate']??'';
        $release=$preorder ? substr($sale['onSaleDate']??$published,0,10) : $published;
        return ['series_hint'=>BookInput::text($seriesName,200),'series_number'=>BookInput::text($series['bookDisplayNumber']??'',10),'series_description'=>BookInput::text($series['description']??($v['seriesInfo']['description']??''),15000),'preorder'=>$preorder,'release'=>$release,'google_id'=>$item['id']??'','title'=>mb_substr($v['title']??'Sans titre',0,250),'subtitle'=>$v['subtitle']??'','authors'=>mb_substr(implode(', ',$v['authors']??[]),0,500),'description'=>BookSynopsis::text($v['description']??''),'isbn'=>$isbn,'publisher'=>$v['publisher']??'','published'=>$v['publishedDate']??'','language'=>$v['language']??'','genre'=>implode(', ',$v['categories']??[]),'page_count'=>$pages>0?$pages:null,'total'=>$pages>0?$pages:null];
    }
    protected function request(string $endpoint,array $params,int $ttl): array
    {
        $key=hash('sha256',$endpoint.json_encode($params));
        $pdo=$this->database->connection();$q=$pdo->prepare('SELECT payload FROM api_cache WHERE cache_key=? AND expires_at>UTC_TIMESTAMP()');$q->execute([$key]);
        if ($ttl>0 && ($cached=$q->fetchColumn())) { return json_decode($cached,true,512,JSON_THROW_ON_ERROR); }
        if ($this->apiKey!=='') { $params['key']=$this->apiKey; }
        $url='https://www.googleapis.com/books/v1'.$endpoint.'?'.http_build_query($params);
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'BibliothequeAtelierNinette/1.0',CURLOPT_WRITEFUNCTION=>function($curl,$chunk) use (&$body) { $body=($body??'').$chunk;return strlen($body)>3000000?0:strlen($chunk); }]);
        for ($attempt=0;$attempt<2;$attempt++) {
            $body='';$ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($ok!==false && !in_array($status,[500,502,503,504],true)) { break; }
            if ($attempt===0) { usleep(250000); }
        }
        if ($ok===false || $status!==200) {
            $message=match($status) { 403,429=>'La recherche est momentanément limitée. Réessayer plus tard.',404=>'Cette édition n’est plus disponible.',default=>'Le catalogue est momentanément indisponible.' };
            throw new \RuntimeException($message);
        }
        $data=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        $q=$pdo->prepare('INSERT INTO api_cache(cache_key,payload,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at)');
        $q->execute([$key,$body,max($ttl,300)]);
        return $data;
    }
}
