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
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Bot\Repository\SecurityProfileIsGranted\TelegramSecurityInterface;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Telegram\Request\Type\TelegramRequestMessage;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Отправляет сообщение выбора начала упаковки заказов
 */
#[AsMessageHandler]
final class TelegramExtraditionMenu
{

    private TelegramSendMessages $telegramSendMessage;
    private TelegramSecurityInterface $telegramSecurity;
    private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram;

    public function __construct(
        TelegramSendMessages $telegramSendMessage,
        ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        TelegramSecurityInterface $TelegramSecurityInterface,
    )
    {
        $this->telegramSendMessage = $telegramSendMessage;
        $this->telegramSecurity = $TelegramSecurityInterface;
        $this->activeProfileByAccountTelegram = $activeProfileByAccountTelegram;
    }

    public function __invoke(TelegramEndpointMessage $message): void
    {
        /** @var TelegramRequestMessage|TelegramRequestCallback $TelegramRequest */
        $TelegramRequest = $message->getTelegramRequest();

        if($TelegramRequest === null)
        {
            return;
        }

        if(!($TelegramRequest instanceof TelegramRequestMessage || $TelegramRequest instanceof TelegramRequestCallback))
        {
            return;
        }

        if($TelegramRequest instanceof TelegramRequestMessage)
        {
            if($TelegramRequest->getText() !== '/menu')
            {
                return;
            }
        }

        if($TelegramRequest instanceof TelegramRequestCallback)
        {
            if($TelegramRequest->getCall() !== 'menu')
            {
                return;
            }
        }

        $profile = $this->activeProfileByAccountTelegram->findByChat($TelegramRequest->getChatId());

        if($profile === null)
        {
            return;
        }


        if(!$this->telegramSecurity->isExistGranted($profile, 'ROLE_PRODUCT_STOCK_PACKAGE'))
        {
            return;
        }

        $this->handle($TelegramRequest);
    }


    /**
     * Отправляет сообщение выбора начала упаковки заказов
     */
    public function handle(TelegramRequestMessage|TelegramRequestCallback $TelegramRequest): void
    {

        $menu[] = [
            'text' => '❌', // Удалить сообщение
            'callback_data' => 'telegram-delete-message'
        ];

        $menu[] = [
            'text' => '📦 ЗАКАЗЫ',
            'callback_data' => TelegramExtraditionProfile::KEY
        ];

        $markup = json_encode([
            'inline_keyboard' => array_chunk($menu, 2),
        ]);

        $currentDate = new DateTimeImmutable();
        $msg = '📦 <b>Упаковка заказов</b>'.PHP_EOL;

        $msg .= sprintf(
            'Вам будет предложено собрать актуальные заказы на <b>%s</b> по одному заказу в порядке поступления.',
            $currentDate->format('d.m')
        );

        $this
            ->telegramSendMessage
            ->delete([$TelegramRequest->getLast(), $TelegramRequest->getSystem()])
            ->chanel($TelegramRequest->getChatId())
            ->message($msg)
            ->markup($markup)
            ->send();
    }
}

