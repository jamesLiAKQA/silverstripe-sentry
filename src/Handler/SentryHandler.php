<?php

/**
 * Class: SentryHandler.
 *
 * @author  Russell Michell 2017-2021 <russ@theruss.com>
 * @package phptek/sentry
 */

namespace PhpTek\Sentry\Handler;

use Throwable;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Sentry\Severity;
use Sentry\EventHint;
use Sentry\Stacktrace;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\ClientBuilder;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Security;
use SilverStripe\Core\Config\Config;
use PhpTek\Sentry\Log\SentryLogger;
use PhpTek\Sentry\Adaptor\SentryAdaptor;
use PhpTek\Sentry\Adaptor\SentrySeverity;
use Sentry\ClientInterface;

/**
 * Monolog handler to send messages to a Sentry (https://github.com/getsentry/sentry) server
 * using sentry-php (https://github.com/getsentry/sentry-php).
 */
class SentryHandler extends AbstractProcessingHandler
{

    use Injectable,
        Configurable;

    /**
     * @var mixed int|null
     */
    private static $log_level = null;

    /**
     * @var mixed SentryLogger|null
     */
    private $logger = null;

    /**
     * Keeps track of the no. times this object is instantiated.
     *
     * @var int
     */
    private static $counter = 0;

    private ClientInterface $client;

    /**
     * @param  int     $level
     * @param  boolean $bubble
     * @param  array   $config
     * @return void
     */
    public function __construct($level = null, bool $bubble = true, array $config = [])
    {
        $this->client = ClientBuilder::create(SentryAdaptor::get_opts() ?: [])->getClient();
        $level = $level ?: $this->config()->get('log_level');
        $level = $level ?? Logger::DEBUG;

        SentrySdk::setCurrentHub(new Hub($this->client));
        
        $config['level'] = $level;

        $this->logger = SentryLogger::factory($this->client, $config);


        parent::__construct($level, $bubble);
    }

    /**
     * write() forms the entry point into the physical sending of the error. The
     * sending itself is done by the current adaptor's `send()` method.
     *
     * @param  LogRecord 
     *
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        $isException = (
            isset($record->context['exception'])
            && $record->context['exception'] instanceof Throwable
        );

        // Ref #65: For some reason, throwing an exception finds its way into both exception + non-exception
        // conditions below.
        if ($isException) {
            static::$counter ++;
        }

        $record = $record->with(
            extra: ['timestamp' => $record->datetime->getTimestamp()]
        );
        $adaptor = $this->logger->getAdaptor();

        // For reasons..this is the only spot where we're able to getCurrentUser()
        $adaptor->setContext('user', SentryLogger::user_data(Security::getCurrentUser()));

        // Create a Sentry EventHint and pass an instance of Stacktrace to it.
        // See SentryAdaptor: We explicitly enable/disable default (Sentry) stacktraces.
        $eventHint = null;

        if (Config::inst()->get(static::class, 'custom_stacktrace')) {
            $eventHint = EventHint::fromArray([
                'stacktrace' => new Stacktrace(SentryLogger::backtrace($record)),
            ]);
        }
        
        // Ref #65 This works around the fact that somewhere in the bowels of Sentry or Monolog,
        // we're managing to trigger the handler twice and send two messages, one of each kind.
        if (static::$counter > 0) {
            return;
        }

        if ($isException) {
            $this->client->captureException(
                $record->context['exception'],
                $adaptor->getContext(),
                $eventHint
            );
        } else {
            $this->client->captureMessage(
                $record->message,
                new Severity(SentrySeverity::process_severity($record->level->getName())),
                $adaptor->getContext(),
                $eventHint
            );
        }
    }

}
