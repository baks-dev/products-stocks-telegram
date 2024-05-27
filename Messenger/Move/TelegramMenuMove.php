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

use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Telegram\Request\Type\TelegramRequestMessage;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TelegramMenuMove
{
    private $security;

    private TelegramSendMessage $telegramSendMessage;
    private LoggerInterface $logger;

    public function __construct(
        Security $security,
        TelegramSendMessage $telegramSendMessage,
        LoggerInterface $productsStocksTelegramLogger
    )
    {
        $this->security = $security;
        $this->telegramSendMessage = $telegramSendMessage;
        $this->logger = $productsStocksTelegramLogger;
    }

    public function __invoke(TelegramEndpointMessage $message): void
    {

        dd($message);

        $this->logger->debug('Telegram Menu Move Handler', [$message]);

        /** @var TelegramRequestMessage|TelegramRequestCallback $TelegramRequest */
        $TelegramRequest = $message->getTelegramRequest();

        if($TelegramRequest instanceof TelegramRequestMessage)
        {
            if($TelegramRequest->getText() !== '/start')
            {
                return;
            }
        }

//        if($TelegramRequest instanceof TelegramRequestCallback)
//        {
//            if($TelegramRequest->getCall() !== 'start')
//            {
//                return;
//            }
//        }

//        if(!$this->security->isGranted('ROLE_USER'))
//        {
//            return;
//        }

        $this->handle($TelegramRequest);
        $message->complete();

    }

    /**
     * Отправляет сообщение выбора начала упаковки перемещений
     */
    public function handle(TelegramRequestMessage|TelegramRequestCallback $TelegramRequest): void
    {
        /** Символ Удалить  */
        $char = "\u274C";
        $decoded = json_decode('["'.$char.'"]');
        $remove = mb_convert_encoding($decoded[0], 'UTF-8');

        $menu[] = [
            'text' => $remove,
            'callback_data' => 'telegram-delete-message'
        ];

        $menu[] = [
            'text' => 'Начать упаковку перемещений',
            'callback_data' => TelegramProfileMove::KEY
        ];

        $markup = json_encode([
            'inline_keyboard' => array_chunk($menu, 2),
        ]);

        $currentDate = new DateTimeImmutable();
        $msg = sprintf(
            'Процесс упаковки перемещений. Вам будет предложено собрать актуальные заказы на <b>%s</b> по одному заказу в порядке поступления.',
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

