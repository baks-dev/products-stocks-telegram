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

use BaksDev\Auth\Telegram\Repository\AccountTelegramRole\AccountTelegramRoleInterface;
use BaksDev\Products\Stocks\Messenger\ProductStockMessage;
use BaksDev\Products\Stocks\Repository\CurrentProductStocks\CurrentProductStocksInterface;
use BaksDev\Products\Stocks\Telegram\Messenger\Move\TelegramMoveProcess;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusMoving;
use BaksDev\Telegram\Api\TelegramSendMessages;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Посылает уведомление всем пользователям о новом заказе на упаковке
 */
#[AsMessageHandler(priority: -100)]
final readonly class TelegramMoveNew
{
    public function __construct(
        #[Target('productsStocksTelegramLogger')] private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private CurrentProductStocksInterface $currentProductStocks,
        private AccountTelegramRoleInterface $accountTelegramRole,
        private TelegramSendMessages $telegramSendMessage,
    ) {}


    public function __invoke(ProductStockMessage $message): void
    {
        $this->entityManager->clear();

        $ProductStockEvent = $this->currentProductStocks->getCurrentEvent($message->getId());

        if(!$ProductStockEvent)
        {
            return;
        }

        // Если Статус не является Статус Moving «Перемещение»
        if(false === $ProductStockEvent->getStatus()->equals(ProductStockStatusMoving::class))
        {
            return;
        }

        $this->logger->info(sprintf('Профиль перемещения %s', $ProductStockEvent->getStocksProfile()));

        /** Получаем всех Telegram пользователей, имеющих доступ к профилю заявки */
        $accounts = $this->accountTelegramRole->fetchAll('ROLE_PRODUCT_STOCK_WAREHOUSE_SEND', $ProductStockEvent->getStocksProfile());

        if(empty($accounts))
        {
            return;
        }

        $menu[] = [
            'text' => '❌', // Удалить сообщение
            'callback_data' => 'telegram-delete-message'
        ];

        $menu[] = [
            'text' => '🔀 Начать сборку',
            'callback_data' => TelegramMoveProcess::KEY.'|'.$ProductStockEvent->getStocksProfile()
        ];

        $markup = json_encode([
            'inline_keyboard' => array_chunk($menu, 2),
        ], JSON_THROW_ON_ERROR);

        $msg = '🔀 <b>Поступила заявка на перемещение</b>'.PHP_EOL;
        $msg .= sprintf('Номер: <b>%s</b>', $ProductStockEvent->getNumber()).PHP_EOL;

        foreach($accounts as $account)
        {
            $this->logger->info(sprintf('Отправили уведомление о заявке на перемещение пользователю %s', $account['chat']));

            $this
                ->telegramSendMessage
                ->chanel($account['chat'])
                ->message($msg)
                ->markup($markup)
                ->send();
        }
    }
}

