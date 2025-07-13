<?php
class FreedomPayCallbackModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $display_header = false;
    public $display_footer = false;
    public $content_only = true;
    private $logFile;

    public function __construct()
    {
        parent::__construct();
        $this->logFile = dirname(__FILE__) . '/../../var/freedompay_' . date('Ymd') . '.log';
    }

    public function postProcess()
    {
        header('Content-Type: text/plain');
        $this->log('📩 Callback received: ' . print_r($_POST, true));

        // 1. Получаем session_token
        $session_token = Tools::getValue('session_token');
        if (!$session_token) {
            $this->log('⛔ Missing session token', true);
            die('MISSING_SESSION_TOKEN');
        }

        // 2. Находим cart_id по token'у
        $cart_id = (int)Db::getInstance()->getValue(
            'SELECT cart_id FROM '._DB_PREFIX_.'freedompay_sessions
             WHERE session_token = "'.pSQL($session_token).'"'
        );
        if (!$cart_id) {
            $this->log("⛔ Invalid session token: $session_token", true);
            die('INVALID_SESSION_TOKEN');
        }
        $this->log("✅ Found cart ID: $cart_id");

        // 3. Проверка подписи
        if (!$this->validateSignature($_POST)) {
            $this->log('⛔ Invalid signature', true);
            die('INVALID_SIGNATURE');
        }

        // 4. Результат оплаты
        $result = (int)Tools::getValue('pg_result');
        $this->log("💳 pg_result = $result for cart $cart_id");

        // 5. Защита от дублей
        if ($existing = Order::getOrderByCartId($cart_id)) {
            $this->log("⚠️ Order $existing already exists for cart $cart_id");
            Db::getInstance()->delete('freedompay_sessions', 'session_token = "'.pSQL($session_token).'"');
            die('ORDER_ALREADY_EXISTS');
        }

        if ($result === 1) {
            // 6. Успешная оплата → создаём заказ
            if ($this->createOrder($cart_id)) {
                $orderId = Order::getOrderByCartId($cart_id);
                if ($orderId) {
                    // 7. Перенос бронирования
                    if (Module::isEnabled('hotelreservationsystem')) {
                        require_once(_PS_MODULE_DIR_.'hotelreservationsystem/classes/HotelBookingDetail.php');
                        HotelBookingDetail::saveOrderBookingData($orderId, $cart_id);
                        $this->log("🏨 Booking migrated for order $orderId");
                        
                        // 8. Обновляем статус заказа
                        $order = new Order($orderId);
                        $history = new OrderHistory();
                        $history->id_order = $order->id;
                        $history->changeIdOrderState(Configuration::get('PS_OS_PAYMENT'), $order->id);
                        $history->add();
                        
                        $this->log("✅ Order status updated to PS_OS_PAYMENT");
                    }
                }
            }
        } else {
            $this->log("❌ Payment failed for cart $cart_id");
        }

        // 9. Чистим таблицу сессий
        Db::getInstance()->delete('freedompay_sessions', 'session_token = "'.pSQL($session_token).'"');
        $this->log("🧹 Session token cleaned");

        die('OK');
    }

    private function validateSignature(array $data)
    {
        if (empty($data['pg_sig'])) {
            $this->log('⛔ Missing pg_sig', true);
            return false;
        }

        $received = $data['pg_sig'];
        unset($data['pg_sig']); // Убираем pg_sig перед подписью

        // Сортировка всех оставшихся ключей
        ksort($data);

        $signParts = ['callback'];
        foreach ($data as $value) {
            $signParts[] = (string)$value;
        }

        // Добавляем секрет в конец
        $secret = Configuration::get('FREEDOMPAY_MERCHANT_SECRET');
        $signParts[] = $secret;

        // Склеиваем и генерим подпись
        $signString = implode(';', $signParts);
        $generated  = md5($signString);

        // Лог
        $this->log("🔐 Signature details:");
        $this->log("  Sign string: $signString");
        $this->log("  Generated: $generated");
        $this->log("  Received: $received");

        return ($generated === $received);
    }

    private function createOrder($cartId)
    {
        $this->log("🛒 Creating order for cart $cartId");

        $cart     = new Cart($cartId);
        $customer = new Customer($cart->id_customer);
        $module   = Module::getInstanceByName('freedompay');

        if (!Validate::isLoadedObject($cart) || !Validate::isLoadedObject($customer) || !Validate::isLoadedObject($module)) {
            $this->log("⛔ Invalid cart, customer, or module", true);
            return false;
        }

        // Используем статус "Оплачено"
        $paidStatusId = Configuration::get('PS_OS_PAYMENT');
        $total = $cart->getOrderTotal(true, Cart::BOTH);

        $module->validateOrder(
            (int)$cart->id,
            $paidStatusId,
            $total,
            'FreedomPay',
            null,
            [],
            $cart->id_currency,
            false,
            $customer->secure_key
        );

        $this->log("✅ Order created: " . $module->currentOrder);
        return true;
    }

    private function log($msg, $isError = false)
    {
        $pref = date('[Y-m-d H:i:s]') . ($isError ? ' [ERROR] ' : ' ');
        file_put_contents($this->logFile, $pref . $msg . PHP_EOL, FILE_APPEND);
        if ($isError) {
            PrestaShopLogger::addLog('FreedomPay: '.$msg, 3);
        }
    }
}
