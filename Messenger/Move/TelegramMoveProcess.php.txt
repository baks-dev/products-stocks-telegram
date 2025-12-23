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

namespace BaksDev\Products\Stocks\Telegram\Messenger\Move;

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Products\Stocks\Telegram\Repository\ProductStockFixed\ProductStockFixedInterface;
use BaksDev\Products\Stocks\Telegram\Repository\ProductStockMoveNext\ProductStockMoveNextInterface;
use BaksDev\Products\Stocks\Type\Event\ProductStockEventUid;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TelegramMoveProcess
{
    public const string KEY = 'GBMaWSqVN';

    public function __construct(
        #[Target('productsStocksTelegramLogger')] private readonly LoggerInterface $logger,
        private readonly TelegramSendMessages $telegramSendMessage,
        private readonly ProductStockFixedInterface $productStockFixed,
        private readonly ProductStockMoveNextInterface $ProductStockMoveNext,
        private readonly ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram
    ) {}

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

        $this->handle($TelegramRequest);
        $message->complete();
    }

    /**
     * Отправляет следующий заказ для упаковки
     */
    public function handle(TelegramRequestCallback $TelegramRequest)
    {
        $CurrentUserProfileUid = $this->activeProfileByAccountTelegram->findByChat($TelegramRequest->getChatId());

        if($CurrentUserProfileUid === null)
        {
            return;
        }

        /** Получаем заявку на перемещение профиля */
        $UserProfileUid = $TelegramRequest->getIdentifier();

        $ProductStockMoveNext = $this->ProductStockMoveNext
            ->findByProfile($UserProfileUid, $CurrentUserProfileUid);


        /** Если заявок больше нет - выводим кнопку главного меню */
        if(!$ProductStockMoveNext)
        {

            $menu[] = [
                'text' => '❌', // Удалить сообщение
                'callback_data' => 'telegram-delete-message'
            ];

            $menu[] = [
                'text' => 'Меню',
                'callback_data' => 'menu'
            ];

            $markup = json_encode([
                'inline_keyboard' => array_chunk($menu, 2),
            ], JSON_THROW_ON_ERROR);


            $msg = '<b>Перeмещения для сборки отсутствуют</b>';

            $this
                ->telegramSendMessage
                ->chanel($TelegramRequest->getChatId())
                ->delete([$TelegramRequest->getId()])
                ->message($msg)
                ->markup($markup)
                ->send();

            return;
        }

        /** Фиксируем полученную заявку за сотрудником */
        $ProductStockEventUid = new ProductStockEventUid($ProductStockMoveNext['stock_event']);
        $this->productStockFixed->fixed($ProductStockEventUid, $CurrentUserProfileUid);

        $this->logger->debug('Зафиксировали заявку за профилем пользователя',
            [
                'number' => $ProductStockMoveNext['stock_number'],
                'ProductStockEventUid' => $ProductStockEventUid,
                'CurrentUserProfileUid' => $CurrentUserProfileUid
            ]);


        /** Получаем заявку на упаковку */

        $msg = '🔀 <b>Перемещение:</b>'.PHP_EOL;

        $msg .= PHP_EOL;

        $msg .= sprintf('Номер: <b>%s</b>', $ProductStockMoveNext['stock_number']).PHP_EOL;
        $msg .= sprintf('Склад отгрузки: <b>%s</b>', $ProductStockMoveNext['users_profile_username']).PHP_EOL;
        $msg .= sprintf('Склад назначения: <b>%s</b>', $ProductStockMoveNext['users_profile_destination']).PHP_EOL;


        /** Получаем продукцию на упаковку */
        $msg .= PHP_EOL;

        $msg .= '<b>Продукция:</b>'.PHP_EOL;

        $products = $this->ProductStockMoveNext->getAllProducts();

        //  $msg .= 'Triangle TR259 235/70 R15 107H | <b>5 шт</b> место 123';
        foreach($products as $product)
        {
            $msg .= PHP_EOL;

            $msg .= sprintf('<b>%s</b> %s: <b>%s</b> %s: <b>%s</b> %s: <b>%s</b> %s',
                    $product['product_name'],

                    $product['product_variation_name'],
                    $product['product_variation_value'],

                    $product['product_modification_name'],
                    $product['product_modification_value'],

                    $product['product_offer_name'],
                    $product['product_offer_value'],

                    trim($product['product_offer_postfix'].' '.$product['product_variation_postfix'].' '.$product['product_modification_postfix']),

                ).PHP_EOL;


            $msg .= sprintf('Количество: <b>%s шт.</b>', $product['product_total']).PHP_EOL;

            $msg .= sprintf('Место хранения: <b>%s шт.</b> %s', $product['stock_storage'], $product['stock_total']).PHP_EOL;

        }

        $menu[] = [
            'text' => '🛑 Отмена',
            'callback_data' => TelegramMoveCancel::KEY.'|'.$ProductStockEventUid
        ];

        $menu[] = [
            'text' => '✅ Укомплектована',
            'callback_data' => TelegramMoveDone::KEY.'|'.$ProductStockEventUid
        ];

        $markup = json_encode([
            'inline_keyboard' => array_chunk($menu, 2),
        ]);

        $this
            ->telegramSendMessage
            ->chanel($TelegramRequest->getChatId())
            ->delete([$TelegramRequest->getId(), $TelegramRequest->getLast()])
            ->message($msg)
            ->markup($markup)
            ->send();
    }
}

