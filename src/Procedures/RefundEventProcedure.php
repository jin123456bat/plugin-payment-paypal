<?php
namespace PayPal\Procedures;

use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;

use PayPal\Services\PaymentService;
use PayPal\Helper\PaymentHelper;

/**
 * Class RefundEventProcedure
 * @package PayPal\Procedures
 */
class RefundEventProcedure
{
    /**
     * @param EventProceduresTriggered $eventTriggered
     * @param PaymentService $paymentService
     * @param PaymentRepositoryContract $paymentContract
     * @param PaymentHelper $paymentHelper
     * @throws \Exception
     */
    public function run(EventProceduresTriggered $eventTriggered,
                        PaymentService $paymentService,
                        PaymentRepositoryContract $paymentContract,
                        PaymentHelper $paymentHelper)
    {
        /** @var Order $order */
        $order = $eventTriggered->getOrder();

        // only sales orders and credit notes are allowed order types to refund
        if($order->typeId == 1   //sales order
        OR $order->typeId == 4)  //credit note
        {
            /** @var Payment[] $payment */
            $payments = $paymentContract->getPaymentsByOrderId($order->id);

            /** @var Payment $payment */
            foreach($payments as $payment)
            {
                if($payment->mopId == $paymentHelper->getPayPalMopId()
                OR $payment->mopId == $paymentHelper->getPayPalExpressMopId())
                {
                    // the paypal transactionsId is mandatory for the paypal refund
                    $properties = $payment->property;

                    if(is_array($properties))
                    {
                        /** @var PaymentProperty $property */
                        foreach($properties as $property)
                        {
                            if($property instanceof PaymentProperty)
                            {
                                if($property->typeId == 1)  //PaymentProperty::TYPE_TRANSACTION_ID
                                {
                                    $saleId = $property->value;
                                    break;
                                }
                            }
                        }
                    }

                    if(isset($saleId))
                    {
                        // refund the payment
                        $refundResult = $paymentService->refundPayment($saleId);

                        if($refundResult['error'])
                        {
                            throw new \Exception($refundResult['error_msg']);
                        }

                        if($refundResult['state'] == 'failed')
                        {
                            //TODO log the reason_code
                        }
                        else
                        {
                            $paymentData = array(
                                'status'    => $refundResult['state'],
                                'currency'  => $refundResult['amount']['currency'],
                                'amount'    => $refundResult['amount']['total'],
                                'entryDate' => $refundResult['create_time'],
                                'saleId'    => $refundResult['id'],
                                'parentId'  => $payment->id,
                                'type'      => 'debit');  //Payment::TYPE_DEBIT

                            // if the refund is pending, set the payment unaccountable
                            if($refundResult['state'] == 'pending')
                            {
                                $paymentData['unaccountable'] = 1;  //1 true 0 false
                            }

                            // create the new debit payment
                            /** @var Payment $debitPayment */
                            $debitPayment = $paymentHelper->createPlentyPayment($paymentData);

                            // get the sale details of the refunded payment
                            $saleDetails = $paymentService->getSaleDetails($saleId);

                            if(!isset($saleDetails['error']))
                            {
                                // read the payment status of the refunded payment
                                $payment->status = $paymentHelper->mapStatus($saleDetails['state']);

                                // update the refunded payment
                                $paymentContract->updatePayment($payment);
                            }

                            if($debitPayment instanceof Payment)
                            {
                                if($refundResult['state'] == 'completed')
                                {
                                    // assign the new debit payment to the order
                                    $paymentHelper->assignPlentyPaymentToPlentyOrder($debitPayment, $order->id);
                                }
                            }
                        }

                        unset($saleId);
                    }
                }
            }
        }
    }
}