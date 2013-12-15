<?php

namespace Bitcont\Bitcoin\Clients\BitcoindInfo;

use Bitcont\Bitcoin\Clients\IParser,
	Bitcont\Bitcoin\Clients\IValidator,
	Bitcont\Bitcoin\Protocol\IAddress,
	Kdyby\Curl\Request,
	Tivoka\Client as Tivoka,
	ReflectionClass;


class Client implements IParser, IValidator
{

	/**
	 * Address API base URL.
	 *
	 * @var string
	 */
	const ADDRESS_API_URL = 'https://blockchain.info/rawaddr/';

	/**
	 * Address API default transaction limit.
	 *
	 * @var int
	 */
	const ADDRESS_API_LIMIT = 50;

	/**
	 * Address API paging buffer.
	 * Prevents the danger of missing transactions due to new
	 * transactions appearing during the download process.
	 *
	 * @var int
	 */
	const ADDRESS_API_BUFFER = 3;


	/**
	 * Prepared prototypes storage.
	 *
	 * @var array
	 */
	protected $prototypes = array();

	/**
	 * Address transactions cache.
	 *
	 * @var array
	 */
	protected $addressTransactions = array();

	/**
	 * Addresses identity map indexed by address id.
	 *
	 * @var array
	 */
	protected $addresses = array();

	/**
	 * Transactions identity map indexed by transaction id.
	 *
	 * @var array
	 */
	protected $transactions = array();

	/**
	 * Bitcoind username.
	 *
	 * @var string
	 */
	protected $bitcoindUsername;

	/**
	 * Bitcoind password.
	 *
	 * @var string
	 */
	protected $bitcoindPassword;

	/**
	 * Stores bitcoind connection.
	 *
	 * @var string
	 */
	protected $bitcoindConnection;


	/**
	 * @param string $bitcoindUsername
	 * @param string $bitcoindPassword
	 */
	public function __construct($bitcoindUsername, $bitcoindPassword)
	{
		$this->bitcoindUsername = $bitcoindUsername;
		$this->bitcoindPassword = $bitcoindPassword;
	}


	/**
	 * Returns address by id.
	 *
	 * @param string $id
	 * @return Address
	 */
	public function getAddress($id)
	{
		if (isset($this->addresses[$id])) {
			return $this->addresses[$id];
		}

		$this->addresses[$id] = $address = $this->assembleAddressInstance($id);
		return $address;
	}


	/**
	 * Returns transactions for given address ordered from the oldest.
	 *
	 * @param IAddress $address
	 * @return array of Transaction
	 */
	public function getTransactions(IAddress $address)
	{
		if (isset($this->addressTransactions[$address->getId()])) {
			return $this->addressTransactions[$address->getId()];
		}

		// construct first page url
		$url = static::ADDRESS_API_URL . $address->getId() . '?limit=' . static::ADDRESS_API_LIMIT;

		// fetch the page
		$request = new Request($url);
		$request->setCertificationVerify(FALSE);
		$response = $request->get()->getResponse();

		// parse the response
		$data = json_decode($response, TRUE);
		$page = 0;

		// loop over all pages
		$transactions = array();
		while (count($data['txs']) > 0) {
			foreach ($data['txs'] as $tx) {
				if (isset($tx['block_height'])) { // include only confirmed transactions
					$transaction = $this->getTransaction($tx['hash']);
					if (!in_array($transaction, $transactions)) {
						$transactions[] = $this->getTransaction($tx['hash']);
					}
				}
			}

			// if there is only one page, end the loop during the first run
			if ($page === 0 && $data['n_tx'] <= static::ADDRESS_API_LIMIT) {
				break;
			}

			// count next offset
			$page++;
			$offset = ($page * static::ADDRESS_API_LIMIT) - ($page * static::ADDRESS_API_BUFFER);
			$nextUrl = "$url&offset=$offset";

			// fetch the page
			$request = new Request($nextUrl);
			$request->setCertificationVerify(FALSE);
			$response = $request->get()->getResponse();

			// parse the response
			$data = json_decode($response, TRUE);
		}

		// the oldest first
		$this->addressTransactions[$address->getId()] = array_reverse($transactions);

		// return result
		return $this->addressTransactions[$address->getId()];
	}


	/**
	 * Returns transaction.
	 *
	 * @param string $id
	 * @return Transaction
	 */
	public function getTransaction($id)
	{
		if (isset($this->transactions[$id])) {
			return $this->transactions[$id];
		}

		// assemble transaction
		$this->transactions[$id] = $transaction = $this->assembleTransactionInstance($id);

		// init bitcoind connection
		$bitcoind = $this->getBitcoindConnection();

		// get raw transaction
		$request = $bitcoind->sendRequest('getrawtransaction', array($id, 1));
		$result = $request->result;

		// fetch inputs
		foreach ($result['vin'] as $in) {

			// get previous transaction data
			$request = $bitcoind->sendRequest('getrawtransaction', array($in['txid'], 1));
			$resultPrevTx = $request->result;

			// connect previous output with current input
			foreach ($resultPrevTx['vout'] as $out) {
				if ($out['n'] == $in['vout']) {
					$this->assembleInputInstance(
						$transaction,
						$this->getAddress($out['scriptPubKey']['addresses'][0]),
						$in['txid'],
						$out['value'] * 100000000
					);
					break;
				}
			}
		}

		// fetch outputs
		foreach ($result['vout'] as $out) {

			// create output instance
			$this->assembleOutputInstance($transaction, $this->getAddress($out['scriptPubKey']['addresses'][0]), $out['value'] * 100000000);
		}

		// return result
		return $transaction;
	}


	/**
	 * Returns TRUE if address is a valid bitcoin address.
	 *
	 * @param string $address
	 * @return bool
	 */
	public function isValidAddress($address)
	{
		// init bitcoind connection
		$bitcoind = $this->getBitcoindConnection();

		// send request
		$request = $bitcoind->sendRequest('validateaddress', array($address));
		$result = $request->result;

		// evaluate result
		return (bool) $result['isvalid'];
	}


	/**
	 * Returns set-up bitcoind json-rpc client.
	 *
	 * @return Tivoka\Client
	 */
	protected function getBitcoindConnection()
	{
		if (isset($this->bitcoindConnection)) {
			return $this->bitcoindConnection;
		}

		$this->bitcoindConnection = Tivoka::connect("http://{$this->bitcoindUsername}:{$this->bitcoindPassword}@localhost:8332/");
		$this->bitcoindConnection->useSpec('1.0');
		return $this->bitcoindConnection;
	}


	/**
	 * Creates a new instance of $className without invoking the constructor.
	 *
	 * @param string $className
	 * @return object
	 */
	protected function assembleInstance($className)
	{
		if (!isset($this->prototypes[$className])) {
			$this->prototypes[$className] = unserialize(sprintf('O:%d:"%s":0:{}', strlen($className), $className));
		}
		return clone $this->prototypes[$className];
	}


	/**
	 * Assembles address instance without invoking the constructor.
	 *
	 * @param string $id
	 * @return Address
	 */
	protected function assembleAddressInstance($id)
	{
		$instance = $this->assembleInstance('Bitcont\Bitcoin\Clients\BitcoindInfo\Address');
		$class = new ReflectionClass($instance);

		$property = $class->getProperty('id');
		$property->setAccessible(true);
		$property->setValue($instance, $id);

		return $instance;
	}


	/**
	 * Assembles transaction instance without invoking the constructor.
	 *
	 * @param string $id
	 * @param array $inputs
	 * @return Transaction
	 */
	protected function assembleTransactionInstance($id)
	{
		$instance = $this->assembleInstance('Bitcont\Bitcoin\Clients\BitcoindInfo\Transaction');
		$class = new ReflectionClass($instance);

		$property = $class->getProperty('id');
		$property->setAccessible(true);
		$property->setValue($instance, $id);

		return $instance;
	}


	/**
	 * Assembles transaction input without invoking the constructor.
	 *
	 * @param Transaction $transaction
	 * @param Address $address
	 * @param string $previousTransactionId
	 * @param int $value
	 * @return Input
	 */
	protected function assembleInputInstance(Transaction $transaction, Address $address, $previousTransactionId, $value)
	{
		$instance = $this->assembleInstance('Bitcont\Bitcoin\Clients\BitcoindInfo\Input');
		$class = new ReflectionClass($instance);

		$property = $class->getProperty('transaction');
		$property->setAccessible(true);
		$property->setValue($instance, $transaction);

		$property = $class->getProperty('address');
		$property->setAccessible(true);
		$property->setValue($instance, $address);

		$property = $class->getProperty('previousTransactionId');
		$property->setAccessible(true);
		$property->setValue($instance, $previousTransactionId);

		$property = $class->getProperty('value');
		$property->setAccessible(true);
		$property->setValue($instance, $value);


		// add input to transaction
		$class = new ReflectionClass($transaction);

		$property = $class->getProperty('inputs');
		$property->setAccessible(true);
		$inputs = $property->getValue($transaction);

		$inputs[] = $instance;
		$property->setValue($transaction, $inputs);

		return $instance;
	}


	/**
	 * Assembles transaction output without invoking the constructor.
	 *
	 * @param Transaction $transaction
	 * @param Address $address
	 * @param int $value
	 * @return Output
	 */
	protected function assembleOutputInstance(Transaction $transaction, Address $address, $value)
	{
		$instance = $this->assembleInstance('Bitcont\Bitcoin\Clients\BitcoindInfo\Output');
		$class = new ReflectionClass($instance);

		$property = $class->getProperty('transaction');
		$property->setAccessible(true);
		$property->setValue($instance, $transaction);

		$property = $class->getProperty('address');
		$property->setAccessible(true);
		$property->setValue($instance, $address);

		$property = $class->getProperty('value');
		$property->setAccessible(true);
		$property->setValue($instance, $value);


		// add output to transaction
		$class = new ReflectionClass($transaction);

		$property = $class->getProperty('outputs');
		$property->setAccessible(true);
		$outputs = $property->getValue($transaction);

		$outputs[] = $instance;
		$property->setValue($transaction, $outputs);

		return $instance;
	}
}

