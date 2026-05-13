<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OmnivaltshippingAjaxModuleFrontController extends ModuleFrontController
{
    public function init(): void
    {
        parent::init();

        if (Tools::getValue('action') === 'saveParcelTerminalDetails') {
            if (!$this->isTokenValid()) {
                die(json_encode(['fail' => 'Invalid token']));
            }
            $this->saveParcelTerminal();
        }
    }

    private function saveParcelTerminal(): void
    {
        $id_cart = (int) $this->context->cart->id;
        $terminal_id = pSQL(Tools::getValue('terminal'));

        OmnivaHelper::printToLog('Cart #' . $id_cart . '. Saving terminal...', 'cart');

        $cartTerminal = new OmnivaCartTerminal($id_cart);
        if (Validate::isLoadedObject($cartTerminal)) {
            $cartTerminal->id_terminal = $terminal_id;
            $result = $cartTerminal->update();
            OmnivaHelper::printToLog('Cart #' . $id_cart . '. Terminal updated to ' . $terminal_id, 'cart');
        } else {
            $cartTerminal->id = $id_cart;
            $cartTerminal->force_id = true;
            $cartTerminal->id_terminal = $terminal_id;
            $result = $cartTerminal->add();
            OmnivaHelper::printToLog('Cart #' . $id_cart . '. Terminal ' . $terminal_id . ' added', 'cart');
        }

        $response = $result
            ? ['success' => 'Terminal saved']
            : ['fail' => 'Failed to save terminal'];

        die(json_encode($response));
    }
}
