<?php
class FreedomPaySuccessModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    
    public function initContent()
    {
        parent::initContent();
        
        $session_token = Tools::getValue('session_token');
        $cart_id = (int)Tools::getValue('id_cart');
        
        // 1. Восстановление корзины по токену
        if ($session_token) {
            $real_cart_id = (int)Db::getInstance()->getValue('
                SELECT cart_id
                FROM '._DB_PREFIX_.'freedompay_sessions
                WHERE session_token = "'.pSQL($session_token).'"
            ');
            
            if ($real_cart_id) {
                $cart_id = $real_cart_id;
                $cart = new Cart($cart_id);
                
                // Для гостевых заказов
                if (!$this->context->customer->isLogged()) {
                    $this->context->cart = $cart;
                    $this->context->cookie->id_cart = $cart_id;
                    $this->context->cookie->write();
                }
                
                $this->module->log("Restored cart from session token: $cart_id");
            }
        }
        
        // 2. Проверка заказа
        $order_id = Order::getOrderByCartId($cart_id);
        
        if ($order_id) {
            $this->redirectToConfirmation($cart_id, $order_id);
        } else {
            $this->createOrderManually($cart_id, $session_token);
        }
    }
    
    private function redirectToConfirmation($cart_id, $order_id)
    {
        $customer = new Customer((new Cart($cart_id))->id_customer);
        
        Tools::redirect($this->context->link->getPageLink(
            'order-confirmation',
            true,
            null,
            [
                'id_cart' => $cart_id,
                'id_module' => $this->module->id,
                'id_order' => $order_id,
                'key' => $customer->secure_key
            ]
        ));
    }
    
    private function createOrderManually($cart_id, $session_token)
    {
        $cart = new Cart($cart_id);
        if (!Validate::isLoadedObject($cart)) {
            $this->module->log("Invalid cart ID: $cart_id", true);
            Tools::redirect($this->context->link->getPageLink('history', true));
        }
        
        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->module->log("Invalid customer for cart: $cart_id", true);
            Tools::redirect($this->context->link->getPageLink('history', true));
        }
        
        $module = Module::getInstanceByName('freedompay');
        
        // Создаем заказ
        $paidStatusId = Configuration::get('PS_OS_PAYMENT');
        $total = $cart->getOrderTotal(true, Cart::BOTH);
        
        $module->validateOrder(
            (int)$cart->id,
            $paidStatusId,
            (float)$total,
            $module->displayName,
            null,
            [],
            (int)$cart->id_currency,
            false,
            $customer->secure_key
        );
        
        $order_id = $module->currentOrder;
        
        if ($order_id) {
            // Перенос бронирования
            if (Module::isEnabled('hotelreservationsystem')) {
                require_once(_PS_MODULE_DIR_.'hotelreservationsystem/classes/HotelBookingDetail.php');
                HotelBookingDetail::saveOrderBookingData($order_id, $cart_id);
                $this->module->log("🏨 Booking migrated for order $order_id");
            }
            
            // Удаляем сессию
            Db::getInstance()->delete('freedompay_sessions', 'session_token = "'.pSQL($session_token).'"');
            
            $this->redirectToConfirmation($cart_id, $order_id);
        } else {
            // Обработка ошибки
            $this->context->smarty->assign([
                'error_message' => $this->module->l('Payment successful but order creation failed. Contact support with cart ID: ').$cart_id
            ]);
            $this->setTemplate('module:freedompay/views/templates/front/payment_error.tpl');
        }
    }
}