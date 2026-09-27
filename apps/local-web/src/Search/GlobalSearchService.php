<?php
declare(strict_types=1);

namespace Sokna\Local\Search;

final class GlobalSearchService
{
    /** @param list<SearchProvider> $providers */
    public function __construct(private readonly array $providers){}

    /** @return array{query:string,results:list<array<string,mixed>>,count:int,min_length:int} */
    public function search(string $raw,array $user,int $limit=14): array
    {
        $query=SearchNormalizer::normalize($raw);$limit=max(1,min(20,$limit));
        if(SearchNormalizer::length($query)<2)return ['query'=>$query,'results'=>[],'count'=>0,'min_length'=>2];
        if(SearchNormalizer::length($query)>80)$query=function_exists('mb_substr')?mb_substr($query,0,80,'UTF-8'):substr($query,0,80);
        $all=[];$perProvider=4;
        foreach($this->providers as $provider)foreach($provider->search($query,$user,$perProvider) as $result)$all[]=$result;
        usort($all,static fn(array $a,array $b)=>((int)($b['score']??0)<=>((int)($a['score']??0)))?:strcmp((string)($a['title']??''),(string)($b['title']??'')));
        $seen=[];$out=[];
        foreach($all as $result){$id=(string)($result['id']??'');if($id===''||isset($seen[$id]))continue;$seen[$id]=true;unset($result['score']);$out[]=$result;if(count($out)>=$limit)break;}
        return ['query'=>$query,'results'=>$out,'count'=>count($out),'min_length'=>2];
    }
}
