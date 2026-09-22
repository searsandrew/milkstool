<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeCustomerRead
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->route('customer');
        $id = $customer instanceof Company ? $customer->netsuite_id : $customer;
        abort_unless($request->user()?->tokenCan('customers:all') || $request->user()?->tokenCan('customer:'.$id), 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
