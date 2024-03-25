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

namespace BaksDev\Products\Stocks\Telegram\Messenger\Notifier;

use BaksDev\Auth\Email\Repository\AccountEventActiveByEmail\AccountEventActiveByEmailInterface;
use BaksDev\Auth\Email\Type\Email\AccountEmail;
use BaksDev\Auth\Telegram\Repository\AccountTelegramEvent\AccountTelegramEventInterface;
use BaksDev\Auth\Telegram\Repository\AccountTelegramRole\AccountTelegramRoleInterface;
use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Auth\Telegram\Type\Status\AccountTelegramStatus\Collection\AccountTelegramStatusCollection;
use BaksDev\Auth\Telegram\UseCase\Admin\NewEdit\AccountTelegramDTO;
use BaksDev\Auth\Telegram\UseCase\Admin\NewEdit\AccountTelegramHandler;
use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Manufacture\Part\Telegram\Type\ManufacturePartDone;
use BaksDev\Products\Stocks\Entity\Event\ProductStockEvent;
use BaksDev\Products\Stocks\Entity\Products\ProductStockProduct;
use BaksDev\Products\Stocks\Entity\ProductStockTotal;
use BaksDev\Products\Stocks\Messenger\ProductStockMessage;
use BaksDev\Products\Stocks\Messenger\Stocks\AddProductStocksTotal\AddProductStocksReserveMessage;
use BaksDev\Products\Stocks\Repository\CurrentProductStocks\CurrentProductStocksInterface;
use BaksDev\Products\Stocks\Repository\ProductStocksById\ProductStocksByIdInterface;
use BaksDev\Products\Stocks\Telegram\Repository\ProductStockFixed\ProductStockFixedInterface;
use BaksDev\Products\Stocks\Type\Event\ProductStockEventUid;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\Collection\ProductStockStatusCollection;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusIncoming;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusMoving;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusPackage;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusWarehouse;
use BaksDev\Telegram\Api\TelegramDeleteMessage;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Bot\Repository\SecurityProfileIsGranted\TelegramSecurityInterface;
use BaksDev\Telegram\Request\TelegramRequest;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Telegram\Request\Type\TelegramRequestIdentifier;
use BaksDev\Telegram\Request\Type\TelegramRequestMessage;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Bundle\SecurityBundle\Security;

#[AsMessageHandler]
final class TelegramIncomingNew
{
    private EntityManagerInterface $entityManager;
    private CurrentProductStocksInterface $currentProductStocks;
    private AccountTelegramRoleInterface $accountTelegramRole;
    private TelegramSendMessage $telegramSendMessage;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        ProductStockStatusCollection $ProductStockStatusCollection,
        CurrentProductStocksInterface $currentProductStocks,
        AccountTelegramRoleInterface $accountTelegramRole,
        TelegramSendMessage $telegramSendMessage,
        LoggerInterface $productsStocksTelegramLogger,
    )
    {
        $ProductStockStatusCollection->cases();
        $this->entityManager = $entityManager;
        $this->accountTelegramRole = $accountTelegramRole;
        $this->telegramSendMessage = $telegramSendMessage;
        $this->currentProductStocks = $currentProductStocks;
        $this->logger = $productsStocksTelegramLogger;
    }

    /**
     * Посылает уведомление всем пользователям о новом приходе
     */
    public function __invoke(ProductStockMessage $message): void
    {
        $this->entityManager->clear();

        $ProductStockEvent = $this->currentProductStocks->getCurrentEvent($message->getId());

        if(!$ProductStockEvent)
        {
            return;
        }

        // Если Статус не является Статус Warehouse «Отправлен на склад»
        if (false === $ProductStockEvent->getStatus()->equals(ProductStockStatusWarehouse::class))
        {
            return;
        }

        /**
         * Получаем всех Telegram пользователей, имеющих доступ к профилю заявки
         */
        $accounts = $this->accountTelegramRole->fetchAll($ProductStockEvent->getProfile(), 'ROLE_PRODUCT_STOCK_INCOMING_ACCEPT');

        if(empty($accounts))
        {
            return;
        }

        $menu[] = [
            'text' => '❌', // Удалить сообщение
            'callback_data' => 'telegram-delete-message'
        ];

//        $menu[] = [
//            'text' => '🔀 Начать сборку',
//            'callback_data' => TelegramMoveProcess::KEY.'|'.$ProductStockEvent->getProfile()
//        ];

        $markup = json_encode([
            'inline_keyboard' => array_chunk($menu, 2),
        ], JSON_THROW_ON_ERROR);

        $msg = '⤵️ <b>Поступила заявка на поступление</b>'.PHP_EOL;
        $msg .= sprintf('Номер: <b>%s</b>', $ProductStockEvent->getNumber()).PHP_EOL;

        foreach($accounts as $account)
        {
            $this->logger->info(sprintf('Отправили уведомление о заявке на поступление пользователю %s', $account['chat']));

            $this
                ->telegramSendMessage
                ->chanel($account['chat'])
                ->message($msg)
                ->markup($markup)
                ->send();
        }
    }
}

