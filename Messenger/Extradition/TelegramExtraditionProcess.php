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

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Products\Stocks\Telegram\Repository\ProductStockFixed\ProductStockFixedInterface;
use BaksDev\Products\Stocks\Telegram\Repository\ProductStockNextExtradition\ProductStockNextExtraditionInterface;
use BaksDev\Products\Stocks\Type\Event\ProductStockEventUid;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TelegramExtraditionProcess
{
    public const KEY = 'eKzkUvKQq';

    private TelegramSendMessage $telegramSendMessage;

    private ProductStockFixedInterface $productStockFixed;
    private ProductStockNextExtraditionInterface $productStockNextExtradition;
    private LoggerInterface $logger;
    private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram;

    public function __construct(

        TelegramSendMessage $telegramSendMessage,
        ProductStockFixedInterface $productStockFixed,
        ProductStockNextExtraditionInterface $productStockNextExtradition,
        LoggerInterface $productsStocksTelegramLogger,
        ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
    )
    {

        $this->telegramSendMessage = $telegramSendMessage;
        $this->productStockFixed = $productStockFixed;
        $this->productStockNextExtradition = $productStockNextExtradition;
        $this->logger = $productsStocksTelegramLogger;
        $this->activeProfileByAccountTelegram = $activeProfileByAccountTelegram;
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

        /** Получаем заявку на упаковку профиля */
        $UserProfileUid = $TelegramRequest->getIdentifier();

        $ProductStockNextExtradition = $this->productStockNextExtradition
            ->findByProfile($UserProfileUid, $CurrentUserProfileUid);

        /** Если заявок больше нет - выводим кнопку главного меню */
        if(!$ProductStockNextExtradition)
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


            $msg = '<b>Заказы для сборки отсутствуют</b>';

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
        $ProductStockEventUid = new ProductStockEventUid($ProductStockNextExtradition['stock_event']);
        $this->productStockFixed->fixed($ProductStockEventUid, $CurrentUserProfileUid);

        $this->logger->debug('Зафиксировали заявку за профилем пользователя',
            [
                'number' => $ProductStockNextExtradition['stock_number'],
                'ProductStockEventUid' => $ProductStockEventUid,
                'UserProfileUid' => $CurrentUserProfileUid
            ]);


        /** Получаем заявку на упаковку */

        $msg = '📦 <b>Упаковка заказа:</b>'.PHP_EOL;

        $msg .= PHP_EOL;

        $msg .= sprintf('Номер: <b>%s</b>', $ProductStockNextExtradition['stock_number']).PHP_EOL;
        $msg .= sprintf('Склад: <b>%s</b>', $ProductStockNextExtradition['users_profile_username']).PHP_EOL;
        $msg .= sprintf('Доставка: <b>%s</b>', $ProductStockNextExtradition['delivery_name']).PHP_EOL;


        /** Получаем продукцию на упаковку */
        $msg .= PHP_EOL;

        $msg .= '<b>Продукция:</b>'.PHP_EOL;

        $products = $this->productStockNextExtradition->getAllProducts();

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
            'callback_data' => TelegramExtraditionCancel::KEY.'|'.$ProductStockEventUid
        ];

        $menu[] = [
            'text' => '✅ Укомплектована',
            'callback_data' => TelegramExtraditionDone::KEY.'|'.$ProductStockEventUid
        ];

        $markup = json_encode([
            'inline_keyboard' => array_chunk($menu, 2),
        ]);

        $this
            ->telegramSendMessage
            ->chanel($TelegramRequest->getChatId())
            ->delete($TelegramRequest->getId())
            ->message($msg)
            ->markup($markup)
            ->send();
    }
}

