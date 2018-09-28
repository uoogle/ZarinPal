<?php

namespace ParsForo\ZarinPal\XF\Payment;

use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;

class ZarinPal extends AbstractProvider
{
	public function getTitle()
	{
		return 'ZarinPal';
	}

	public function getApiEndpoint()
	{
		//if (\XF::config('enableLivePayments'))
		if ($this->test_mode)
		{
			return (object)array('wsdl'=>'https://sandbox.zarinpal.com/pg/services/WebGate/wsdl','web'=>'https://sandbox.zarinpal.com/pg/StartPay/');
		}
		else
		{
			return (object)array('wsdl'=>'https://de.zarinpal.com/pg/services/WebGate/wsdl','web'=>'https://www.zarinpal.com/pg/StartPay/');
		}
	}

	public function verifyConfig(array &$options, &$errors = [])
	{
		if (empty($options['zarinpal_merchant']))
		{
			$errors[] = \XF::phrase('zarinpal_you_must_provide_zarinpal_merchant');
		}
		if (isset($options['zarinpal_zaringate'],$options['zarinpal_sepgate']) && $options['zarinpal_zaringate']==1 && $options['zarinpal_sepgate']==1)
		{
			$errors[] = \XF::phrase('zarinpal_zarin_sep_error');
		}
		return ($errors ? false : true);
	}

	public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
	{
		$this->test_mode = isset($purchase->paymentProfile->options['zarinpal_testmode']) && $purchase->paymentProfile->options['zarinpal_testmode'] == 1;
		$client = new \SoapClient($this->getApiEndpoint()->wsdl, array('encoding' => 'UTF-8'));
		$result = $client->PaymentRequest($parameters = array(
				'MerchantID'  => $purchase->paymentProfile->options['zarinpal_merchant'],
				'Amount'      => intval(ceil($purchase->cost / 10)),
				'Description' => ($purchase->title?:('Invoice#'.time())),
				'Email'       => $purchase->purchaser->email,
				'Mobile'      => '',
				'CallbackURL' => $this->getCallbackUrl().'&custom='.$purchaseRequest->request_key.'&amount='.$purchase->cost
			));
		$endpointUrl = $this->getApiEndpoint()->web.$result->Authority;
		if ( isset($purchase->paymentProfile->options['zarinpal_zaringate']) && $purchase->paymentProfile->options['zarinpal_zaringate'] == 1 )
		{
			$endpointUrl .= '/ZarinGate';
		}
		elseif ( isset($purchase->paymentProfile->options['zarinpal_sepgate']) && $purchase->paymentProfile->options['zarinpal_sepgate'] == 1 )
		{
			$endpointUrl .= '/Sep';
		}
		@session_start();
		$_SESSION[$result->Authority.'1'] = $purchase->returnUrl;
		$_SESSION[$result->Authority.'2'] = $purchase->cancelUrl;
		setcookie($result->Authority.'1', $purchase->returnUrl, time()+1200, '/');
		setcookie($result->Authority.'2', $purchase->cancelUrl, time()+1200, '/');
		return $controller->redirect($endpointUrl, '');
	}

	public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING)
	{
		return false;
	}

	public function setupCallback(\XF\Http\Request $request)
	{
		$state = new CallbackState();
		$state->transactionId = $request->filter('Authority', 'str');
		$state->costAmount = $request->filter('amount', 'unum');
		$state->taxAmount = 0;
		$state->costCurrency = 'IRR';
		$state->paymentStatus = $request->filter('Status', 'str');
		$state->requestKey = $request->filter('custom', 'str');
		$state->ip = $request->getIp();
		$state->_POST = $_REQUEST;
		return $state;
	}

	public function validateTransaction(CallbackState $state)
	{
		if (!$state->requestKey)
		{
			$state->logType = 'info';
			$state->logMessage = 'No purchase request key. Unrelated payment, no action to take.';
			return false;
		}
		if (!$state->getPurchaseRequest())
		{
			$state->logType = 'info';
			$state->logMessage = 'Invalid request key. Unrelated payment, no action to take.';
			return false;
		}
		if (!$state->transactionId)
		{
			$state->logType = 'info';
			$state->logMessage = 'No transaction or subscriber ID. No action to take.';
			return false;
		}
		$paymentRepo = \XF::repository('XF:Payment');
		$matchingLogsFinder = $paymentRepo->findLogsByTransactionId($state->transactionId);
		if ($matchingLogsFinder->total())
		{
			$state->logType = 'info';
			$state->logMessage = 'Transaction already processed. Skipping.';
			return false;
		}
		return parent::validateTransaction($state);
	}

	/*
	public function validateCallback(CallbackState $state)
	{
		return parent::validateCallback($state);
	}
	public function validatePurchaseRequest(CallbackState $state)
	{
		return parent::validatePurchaseRequest($state);
	}
	public function validatePurchasableHandler(CallbackState $state)
	{
		return parent::validatePurchasableHandler($state);
	}
	public function validatePaymentProfile(CallbackState $state)
	{
		return parent::validatePaymentProfile($state);
	}
	public function validatePurchaser(CallbackState $state)
	{
		return parent::validatePurchaser($state);
	}
	public function validatePurchasableData(CallbackState $state)
	{
		return parent::validatePurchasableData($state);
	}
	*/

	public function validateCost(CallbackState $state)
	{
		$upgradeRecord = false;
		$purchaseRequest = $state->getPurchaseRequest();
		$cost = $purchaseRequest->cost_amount;
		$currency = $purchaseRequest->cost_currency;
		$costValidated = (round(($state->costAmount - $state->taxAmount), 2) == round($cost, 2) && $state->costCurrency == $currency);
		if (!$costValidated)
		{
			$state->logType = 'error';
			$state->logMessage = 'Invalid cost amount';
			return false;
		}
		return true;
	}

	public function getPaymentResult(CallbackState $state)
	{
		if (strtolower($state->paymentStatus) == 'ok')
		{
			$state->paymentResult = CallbackState::PAYMENT_RECEIVED;
		}
		else
		{
			$state->paymentResult = CallbackState::PAYMENT_REINSTATED;
		}
	}

	public function prepareLogData(CallbackState $state)
	{
		$state->logDetails = $state->_POST;
	}


	public function completeTransaction(CallbackState $state)
	{
		@session_start();
		$router = \XF::app()->router('public');
		$returnUrl = $_SESSION[$state->transactionId.'1'];
		$cancelUrl = $_SESSION[$state->transactionId.'2'];
		if (!$returnUrl) $returnUrl = $_COOKIE[$state->transactionId.'1'];
		if (!$cancelUrl) $cancelUrl = $_COOKIE[$state->transactionId.'2'];
		if (!$returnUrl) $returnUrl = $router->buildLink('canonical:account/upgrade-purchase');
		if (!$cancelUrl) $cancelUrl = $router->buildLink('canonical:account/upgrades');
		unset($_SESSION[$state->transactionId.'1'],$_SESSION[$state->transactionId.'2']);
		setcookie($state->transactionId.'1', './?', time(), '/');
		setcookie($state->transactionId.'2', './?', time(), '/');
		$url = $cancelUrl;
		if (strtolower($state->paymentStatus) == 'ok')
		{
			$this->test_mode = isset($state->paymentProfile->options['zarinpal_testmode']) && $state->paymentProfile->options['zarinpal_testmode'] == 1;
			$state->testMode = $this->test_mode; //(\XF::config('enableLivePayments'));
			$client = new \SoapClient($this->getApiEndpoint()->wsdl, array('encoding' => 'UTF-8'));
			$result = $client->PaymentVerification($parameters = array(
					'MerchantID' => $state->paymentProfile->options['zarinpal_merchant'],
					'Authority'  => $state->transactionId,
					'Amount'     => intval(ceil($state->costAmount / 10)),
				));
			if($result->Status == 100)
			{
				$state->transactionId = $result->RefID;
				parent::completeTransaction($state);
				$url = $returnUrl;
			}
			else
			{
				$state->logType = 'error';
				$state->logMessage = 'Payment Failed. error:'.$result->Status;
			}
		}
		else
		{
			$state->logType = 'error';
			$state->logMessage = 'Payment Cancelled';
		}
		@header('location: '.$url);
		echo '<script>document.location="'.$url.'";</script>';
		exit;
	}

}