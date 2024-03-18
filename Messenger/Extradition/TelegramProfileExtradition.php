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

use BaksDev\Menu\Admin\Repository\MenuAuthority\MenuAuthorityRepositoryInterface;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Users\Profile\UserProfile\Repository\CurrentAllUserProfiles\CurrentAllUserProfilesByUserInterface;
use BaksDev\Users\User\Type\Id\UserUid;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;


#[AsMessageHandler]
final class TelegramProfileExtradition
{
    public const KEY = 'uAcQHYHzqF';

    private $security;

    private TelegramSendMessage $telegramSendMessage;
    private MenuAuthorityRepositoryInterface $menuAuthorityRepository;
    private CurrentAllUserProfilesByUserInterface $currentAllUserProfilesByUser;
    private LoggerInterface $logger;

    public function __construct(
        Security $security,
        TelegramSendMessage $telegramSendMessage,
        MenuAuthorityRepositoryInterface $menuAuthorityRepository,
        CurrentAllUserProfilesByUserInterface $currentAllUserProfilesByUser,
        LoggerInterface $productsStocksTelegramLogger
    )
    {
        $this->security = $security;
        $this->telegramSendMessage = $telegramSendMessage;
        $this->menuAuthorityRepository = $menuAuthorityRepository;
        $this->currentAllUserProfilesByUser = $currentAllUserProfilesByUser;
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

        if(!$this->security->isGranted('ROLE_USER'))
        {
            return;
        }

        $this->handle($TelegramRequest);
        $message->complete();

    }

    /**
     * Выбор профиля пользователя начала упаковки заказов
     */
    public function handle(TelegramRequestCallback $TelegramRequest): void
    {

        $User = $this->security->getToken()?->getUser();

        /**
         * Получаем собственные профили пользователя
         */

        $UserUid = $User?->getId();

        /** TODO профиль  */
        $UserUid = new UserUid('018d464c-26cb-7fcb-aa15-ba9ec661740e');

        $profiles = $this->currentAllUserProfilesByUser->fetchUserProfilesAllAssociative($UserUid);

        foreach($profiles as $profile)
        {
            $menu[] = [
                'text' => $profile['user_profile_username'],
                'callback_data' => TelegramExtraditionProcess::KEY.'|'.$profile['user_profile_id']
            ];
        }

        /**
         * Получаем профили доверенностей
         */

        $UserProfileUid = $User?->getProfile();
        $profiles = $this->menuAuthorityRepository->findAll($UserProfileUid);

        foreach($profiles as $profile)
        {
            $menu[] = [
                'text' => $profile['authority_username'],
                'callback_data' => TelegramExtraditionProcess::KEY.'|'.$profile['authority']
            ];
        }

        $markup = null;

        if(!empty($menu))
        {
            $markup = json_encode([
                'inline_keyboard' => array_chunk($menu, 1),
            ]);
        }

        $msg = '<b>Выберите профиль пользователя:</b>';
        $msg .= PHP_EOL;

        $this
            ->telegramSendMessage
            ->delete([$TelegramRequest->getSystem()])
            ->chanel($TelegramRequest->getChatId())
            ->message($msg)
            ->markup($markup)
            ->send();

        $this->logger->debug('Пользователь начал процесс упаковки заказов', ['UserUid' => $UserUid]);
    }
}

