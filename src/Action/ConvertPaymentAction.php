<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Action;

use Setono\Payum\QuickPay\Action\Api\ApiAwareTrait;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Model\PaymentInterface;
use Payum\Core\Request\Convert;
use Payum\Core\Bridge\Spl\ArrayObject;

class ConvertPaymentAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * {@inheritdoc}
     *
     * @param Convert $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $paymentModel */
        $paymentModel = $request->getSource();

        $token = $request->getToken();

        $details = ArrayObject::ensureArrayObject($paymentModel->getDetails());
        $details['amount'] = $paymentModel->getTotalAmount();
        $details['payment'] = $paymentModel;

        if (!isset($details['quickpayPayment']) || !$details['quickpayPayment'] instanceof QuickPayPayment) {
            $details['quickpayPayment'] = $this->api->getPayment($details);
            $details['quickpayPaymentId'] = $details['quickpayPayment']->getId();
        }
        $details['continue_url'] = $details['cancel_url'] = $token->getAfterUrl();

        $request->setResult((array) $details);
    }

    /**
     * {@inheritdoc}
     */
    public function supports($request)
    {
        return
            $request instanceof Convert &&
            $request->getSource() instanceof PaymentInterface &&
            'array' === $request->getTo();
    }
}
