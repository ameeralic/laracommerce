<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Darryldecode\Cart\CartCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CheckoutController extends Controller
{
    //
    public function index()
    {
        return Inertia::render('Checkout/Index',[
            'userInfo' => auth()->user()
        ]);
    }
    public function checkout(Request $request)
    {

        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
        \Cart::session(Auth::user()->id);
        $cartContent =  \Cart::getContent();
        $cartTotal =  \Cart::getTotal();
        $lineItems = [];

        foreach ($cartContent as $cartItem) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'inr',
                    'product_data' => [
                        'name' => $cartItem->name,
                        // 'images' => [$product->image],
                    ],
                    'unit_amount' => $cartItem->price * 100,
                ],
                'quantity' => $cartItem->quantity,
            ];
        }
        $session = \Stripe\Checkout\Session::create([
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => route('checkout.success', [], true) . "?session_id={CHECKOUT_SESSION_ID}",
            'cancel_url' => route('checkout.cancel', [], true),
        ]);

        $attributes = $request->validate([
            'first_name' => 'required',
            'last_name' => 'required',
            'phone_number' => 'required',
            'email' => 'required',
            'address_line_1' => 'required',
            'address_line_2' => 'required',
            'city' => 'required',
            'pin_code' => 'required',
            'country' => 'required',
        ]);

        $order_items = [];
        foreach ($cartContent as $cartItem) {
            $order_items[] = [
                'id' => $cartItem->id,
                'name' => $cartItem->name,
                'price' => $cartItem->price,
                'quantity' => $cartItem->quantity,
            ];
        }
        // dd($order_items);   
        $order = new Order();
        $order->status = 'unpaid';
        $order->user_id = Auth::user()->id;
        $order->shipping_address = json_encode($attributes);
        $order->cart_content = json_encode($order_items);
        $order->total_price = $cartTotal;
        $order->session_id = $session->id;
        $order->save();

        \Cart::session(Auth::user()->id)->clear();

        return Inertia::location($session->url);
    }
    public function success(Request $request)
    {
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
        $sessionId = $request->get('session_id');

        try {
            $session = \Stripe\Checkout\Session::retrieve($sessionId);
            if (!$session) {
                throw new NotFoundHttpException;
            }

            $order = Order::where('session_id', $session->id)->where('status','unpaid')->first();
            $customer = User::find($order->user_id)->first_name;
            if (!$order) {
                throw new NotFoundHttpException();
            }
            if ($order->status === 'unpaid') {
                $order->status = 'paid';
                $order->save();
            }
            return Inertia::render('Checkout/Success',['customer' => $customer]);
        } catch (\Exception $e) {
            throw new NotFoundHttpException();
        }
    }
    public function cancel()
    {
        return "failed";
    }
}
