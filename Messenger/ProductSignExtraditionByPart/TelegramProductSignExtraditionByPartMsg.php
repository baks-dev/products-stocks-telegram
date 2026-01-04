<?php
/*
 *  Copyright 2025.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Products\Stocks\Telegram\Messenger\ProductSignExtraditionByPart;

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Core\Twig\CallTwigFuncExtension;
use BaksDev\Products\Stocks\Repository\AllProductStocksPart\AllProductStocksPart\AllProductStocksOrdersPartInterface;
use BaksDev\Products\Stocks\Security\VoterPart;
use BaksDev\Products\Stocks\Type\Part\ProductStockPartUid;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\Type\TelegramRequestIdentifier;
use BaksDev\Users\Profile\Group\Repository\ExistRoleByProfile\ExistRoleByProfileInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

#[AsMessageHandler]
final readonly class TelegramProductSignExtraditionByPartMsg
{
    public const string KEY = 'uxkhqnCjEY';

    public function __construct(
        #[Target('productsStocksTelegramLogger')] private LoggerInterface $logger,
        private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        private TelegramSendMessages $telegramSendMessage,
        private Security $security,
        private AllProductStocksOrdersPartInterface $AllProductStocksOrdersPartRepository,
        private ExistRoleByProfileInterface $ExistRoleByProfileRepository,
        private Environment $environment,

    ) {}


    /**
     * Получаем состояние упаковки и отправляем соответствующие действия пользователю
     */
    public function __invoke(TelegramEndpointMessage $message): void
    {
        /** @var TelegramRequestIdentifier $TelegramRequest */
        $TelegramRequest = $message->getTelegramRequest();

        if(
            false === ($TelegramRequest instanceof TelegramRequestIdentifier) ||
            false === $this->security->isGranted('ROLE_USER')
        )
        {
            return;
        }

        /**
         * Проверяем, что профиль пользователя чата активный
         */

        $UserProfileUid = $this->activeProfileByAccountTelegram
            ->findByChat($TelegramRequest->getChatId());

        if(false === ($UserProfileUid instanceof UserProfileUid))
        {
            $this->logger->warning('Активный профиль пользователя не найден', [
                __FILE__.''.__LINE__,
                'chat' => $TelegramRequest->getChatId(),
            ]);

            return;
        }

        /**
         * Проверяем, что профиль пользователя чата соответствует правилам доступа «ROLE_PRODUCT_STOCK_PART»
         */

        $isGranted = $this->ExistRoleByProfileRepository
            ->isExistRole($UserProfileUid, VoterPart::getVoter());

        if(false === $isGranted)
        {
            $this->logger->warning('Пользователь не имеет достаточно прав для выполнения действий', [
                __FILE__.''.__LINE__,
                'role' => VoterPart::getVoter(),
                'chat' => $TelegramRequest->getChatId(),
            ]);

            return;
        }

        $this->telegramSendMessage->chanel($TelegramRequest->getChatId());


        /**
         * Получаем упаковку товара по заказам
         */

        $ProductStockPartUid = new ProductStockPartUid($TelegramRequest->getIdentifier());

        $result = $this->AllProductStocksOrdersPartRepository
            ->forProductStockPart($ProductStockPartUid)
            ->onlyPackageStatus()
            ->findAll();

        if(false === $result || false === $result->valid())
        {
            $this->logger->warning(sprintf('%s: Упаковка с идентификатором в статусе «Package» не найдена', $ProductStockPartUid), [
                __FILE__.''.__LINE__,
                'chat' => $TelegramRequest->getChatId(),
            ]);

            return;
        }


        $caption = '<b>Сборка продукции:</b>';
        $caption .= PHP_EOL;

        /** Перечисляем продукцию в упаковке и её заказы */

        $call = $this->environment->getExtension(CallTwigFuncExtension::class);

        foreach($result as $ProductStocksOrdersPartResult)
        {

            $strOffer = '';

            /**
             * Множественный вариант
             */

            $variation = $call->call(
                $this->environment,
                $ProductStocksOrdersPartResult->getProductVariationValue(),
                $ProductStocksOrdersPartResult->getProductVariationReference().'_render',
            );

            $strOffer .= $variation ? ' '.trim($variation) : '';

            /**
             * Модификация множественного варианта
             */

            $modification = $call->call(
                $this->environment,
                $ProductStocksOrdersPartResult->getProductModificationValue(),
                $ProductStocksOrdersPartResult->getProductModificationReference().'_render',
            );

            $strOffer .= $modification ? trim($modification) : '';

            /**
             * Торговое предложение
             */

            $offer = $call->call(
                $this->environment,
                $ProductStocksOrdersPartResult->getProductOfferValue(),
                $ProductStocksOrdersPartResult->getProductOfferReference().'_render',
            );

            $strOffer .= $modification ? ' '.trim($offer) : '';

            $strOffer .= $ProductStocksOrdersPartResult->getProductOfferPostfix()
                ? ' '.$ProductStocksOrdersPartResult->getProductOfferPostfix() : '';

            $strOffer .= $ProductStocksOrdersPartResult->getProductVariationPostfix()
                ? ' '.$ProductStocksOrdersPartResult->getProductVariationPostfix() : '';

            $strOffer .= $ProductStocksOrdersPartResult->getProductModificationPostfix()
                ? ' '.$ProductStocksOrdersPartResult->getProductModificationPostfix() : '';


            /** Сообщение */

            $caption .= $ProductStocksOrdersPartResult->getProductName().' ';
            $caption .= $strOffer.' ';

            $caption .= ' | <b>'.$ProductStocksOrdersPartResult->getTotal().' шт.</b>';

            //$parts[(string) $ProductStockPartUid]['name'] = $ProductStocksOrdersPartResult->getProductName();
            //$parts[(string) $ProductStockPartUid]['offer'] = $strOffer;
            //$parts[(string) $ProductStockPartUid]['total'] = $ProductStocksOrdersPartResult->getTotal();

            $caption .= PHP_EOL;
            $caption .= PHP_EOL;
            $caption .= '<b>Сборка заказов:</b>';
            $caption .= PHP_EOL;

            /** Номера заказов */
            foreach($ProductStocksOrdersPartResult->getOrdersCollection() as $order)
            {
                $caption .= $order->number;
                $caption .= PHP_EOL;
            }

            $caption .= PHP_EOL;

            $menu[] = [
                'text' => '❌ Удалить сообщение', // Удалить сообщение
                'callback_data' => 'telegram-delete-message',
            ];

            /** @see TelegramManufacturePartDone */

            $menu[] = [
                'text' => sprintf(
                    'Укомплектовано все %s шт.',
                    $ProductStocksOrdersPartResult->getTotal(),
                ),
                'callback_data' => sprintf('%s|%s', TelegramProductSignExtraditionByPartDone::KEY, $ProductStockPartUid),
            ];


            /** Отправляем сообщение */

            $markup = json_encode([
                'inline_keyboard' => array_chunk($menu, 1),
            ], JSON_THROW_ON_ERROR);


            $this->telegramSendMessage
                ->delete([$TelegramRequest->getId()])
                ->message($caption)
                ->markup($markup)
                ->send();

        }
    }
}