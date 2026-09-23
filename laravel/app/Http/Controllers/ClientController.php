<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $clients=Client::query()->whereNull('deleted_at')
            ->when($request->filled('q'),function($q)use($request){
                $term='%'.$request->string('q').'%';
                $q->where(fn($x)=>$x->where('name','like',$term)->orWhere('phone','like',$term)->orWhere('id','like',$term));
            })->orderBy('name')->paginate(25)->withQueryString();
        return view('clients.index',compact('clients'));
    }

    public function show(Client $client)
    {
        abort_if($client->deleted_at!==null,404);
        return view('clients.show',compact('client'));
    }
}
