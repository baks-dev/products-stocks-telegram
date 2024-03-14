<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Products\Stocks\Telegram\Messenger\Extradition;

use BaksDev\Auth\Email\Repository\AccountEventActiveByEmail\AccountEventActiveByEmailInterface;
use BaksDev\Auth\Email\Type\Email\AccountEmail;
use BaksDev\Auth\Telegram\Repository\AccountTelegramEvent\AccountTelegramEventInterface;
use BaksDev\Auth\Telegram\Type\Status\AccountTelegramStatus\Collection\AccountTelegramStatusCollection;
use BaksDev\Auth\Telegram\UseCase\Admin\NewEdit\AccountTelegramDTO;
use BaksDev\Auth\Telegram\UseCase\Admin\NewEdit\AccountTelegramHandler;
use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Core\Doctrine\ORMQueryBuilder;
use BaksDev\Manufacture\Part\Telegram\Type\ManufacturePartDone;
use BaksDev\Products\Stocks\Entity\Event\ProductStockEvent;
use BaksDev\Products\Stocks\Entity\ProductStock;
use BaksDev\Products\Stocks\Telegram\Repository\ProductStockFixed\ProductStockFixedInterface;
use BaksDev\Products\Stocks\Type\Event\ProductStockEventUid;
use BaksDev\Products\Stocks\Type\Id\ProductStockUid;
use BaksDev\Products\Stocks\UseCase\Admin\Extradition\ExtraditionProductStockDTO;
use BaksDev\Products\Stocks\UseCase\Admin\Extradition\ExtraditionProductStockHandler;
use BaksDev\Telegram\Api\TelegramDeleteMessage;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\TelegramRequest;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Telegram\Request\Type\TelegramRequestIdentifier;
use BaksDev\Telegram\Request\Type\TelegramRequestMessage;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\User\Type\Id\UserUid;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Bundle\SecurityBundle\Security;

#[AsMessageHandler]
final class TelegramExtraditionDone
{
    public const KEY = 'XZabntnqK';

    private $security;
    private TelegramSendMessage $telegramSendMessage;
    private ProductStockFixedInterface $productStockFixed;
    private TelegramExtraditionProcess $extraditionProcess;
    private ORMQueryBuilder $ORMQueryBuilder;
    private ExtraditionProductStockHandler $extraditionProductStockHandler;
    private LoggerInterface $logger;

    public function __construct(
        ORMQueryBuilder $ORMQueryBuilder,
        Security $security,
        TelegramSendMessage $telegramSendMessage,
        TelegramExtraditionProcess $extraditionProcess,
        ExtraditionProductStockHandler $extraditionProductStockHandler,
        LoggerInterface $productsStocksTelegramLogger
    )
    {
        $this->security = $security;
        $this->telegramSendMessage = $telegramSendMessage;
        $this->extraditionProcess = $extraditionProcess;
        $this->ORMQueryBuilder = $ORMQueryBuilder;
        $this->extraditionProductStockHandler = $extraditionProductStockHandler;
        $this->logger = $productsStocksTelegramLogger;
    }

    public function __invoke(TelegramEndpointMessage $message): void
    {
        /** @var TelegramRequestCallback $TelegramRequest */
        $TelegramRequest = $message->getTelegramRequest();

        if(!($TelegramRequest instanceof TelegramRequestCallback))
        {
            return;
        }

        if($TelegramRequest->getCall() !== self::KEY)
        {
            return;
        }

        if(empty($TelegramRequest->getIdentifier()))
        {
            return;
        }

        if(false === $this->security->isGranted('ROLE_USER'))
        {
            return;
        }

        $this
            ->telegramSendMessage
            ->chanel($TelegramRequest->getChatId());

        $this->handle($TelegramRequest);
    }

    /**
     * Заявка выполнена
     */
    public function handle(TelegramRequestCallback $TelegramRequest): void
    {

        /**
         * Получаем событие
         */

        $ProductStockEventUid = new ProductStockEventUid($TelegramRequest->getIdentifier());

        $orm = $this->ORMQueryBuilder->createQueryBuilder(self::class);

        /** @var ProductStockEvent $ProductStockEvent */
        $ProductStockEvent = $orm
            ->from(ProductStockEvent::class, 'event')
            ->where('event.id = :event')
            ->setParameter('event', $ProductStockEventUid, ProductStockUid::TYPE)
            ->getOneOrNullResult()
        ;

        if(!$ProductStockEvent)
        {
            return;
        }

        /**
         * Делаем отметку о комплектации
         */

        $ExtraditionProductStockDTO = new ExtraditionProductStockDTO();
        $ProductStockEvent->getDto($ExtraditionProductStockDTO);
        $ProductStock = $this->extraditionProductStockHandler->handle($ExtraditionProductStockDTO);

        if(!$ProductStock instanceof ProductStock)
        {
            $msg = sprintf('%s: Возникла ошибка при упаковке заказа', $ProductStock);

            $this
                ->telegramSendMessage
                ->delete([$TelegramRequest->getId()])
                ->message($msg)
                ->send();

            return;
        }


        $msg = '<b>Заказ укомплектован:</b>';
        $msg .= PHP_EOL;

        $msg .= sprintf('Номер: <b>%s</b>', $ProductStockEvent->getNumber()) ;
        $msg .= PHP_EOL;

        $currentDate = new DateTimeImmutable();
        $msg .= sprintf('Дата: <b>%s</b>', $currentDate->format('d.m.Y H:i')) ;

        $this
            ->telegramSendMessage
            ->delete([$TelegramRequest->getId()])
            ->message($msg)
            ->send();


        /**
         * Получаем идентификатор профиля заявки и Отправляем следующую заявку на сборку
         */

        $profile = $ExtraditionProductStockDTO->getProfile();
        $TelegramRequest->setIdentifier((string) $profile);
        $this->extraditionProcess->handle($TelegramRequest);


        $User = $this->security->getToken()?->getUser();

        $this->logger->debug('Пользователь укомплектовал заказ', [
            'number' => $ProductStockEvent->getNumber(),
            'ProductStockEventUid' => $ProductStockEvent->getId(),
            'Current UserProfileUid' => $User?->getProfile()
        ]);

    }

}

