# Документ требований: OAuth Social Login

## Введение

Добавление возможности регистрации и входа на платформу ANORA через внешних OAuth-провайдеров — Google (OAuth 2.0 / OpenID Connect) и Apple (Sign in with Apple). Функциональность включает создание новых аккаунтов через провайдеров, вход существующих пользователей, привязку OAuth-аккаунтов к существующим email/password аккаунтам, а также корректную обработку граничных случаев (совпадение email, приватный relay Apple и т.д.).

## Глоссарий

- **OAuth_Service** — серверный PHP-модуль, обрабатывающий OAuth 2.0 / OpenID Connect потоки для Google и Apple
- **OAuth_Accounts_Table** — таблица базы данных `oauth_accounts`, хранящая связи между пользователями и OAuth-провайдерами
- **Frontend** — React 18 + Vite клиентское приложение (SPA)
- **Auth_API** — набор PHP-эндпоинтов в `backend/api/auth/`, обрабатывающих аутентификацию
- **Provider** — внешний OAuth-сервис (Google или Apple)
- **Provider_User_ID** — уникальный идентификатор пользователя, выданный провайдером (Google `sub` или Apple `sub`)
- **ID_Token** — JWT-токен от провайдера, содержащий claims пользователя (email, sub и т.д.)
- **State_Parameter** — криптографически случайное значение, используемое для защиты от CSRF в OAuth-потоке
- **Nonce** — криптографически случайное значение, привязанное к ID Token для предотвращения replay-атак
- **Private_Relay_Email** — анонимный email-адрес, генерируемый Apple для пользователей, скрывающих свой реальный email

## Требования

### Требование 1: Хранение OAuth-аккаунтов в базе данных

**User Story:** Как разработчик, я хочу хранить связи между пользователями и OAuth-провайдерами в отдельной таблице, чтобы один пользователь мог иметь несколько привязанных провайдеров.

#### Критерии приёмки

1. THE OAuth_Accounts_Table SHALL содержать колонки: `id`, `user_id`, `provider` (enum: google, apple), `provider_user_id`, `provider_email`, `created_at`
2. THE OAuth_Accounts_Table SHALL иметь уникальный составной индекс на (`provider`, `provider_user_id`), гарантирующий что один аккаунт провайдера привязан только к одному пользователю
3. THE OAuth_Accounts_Table SHALL иметь внешний ключ `user_id`, ссылающийся на `users.id` с каскадным удалением
4. THE OAuth_Accounts_Table SHALL иметь индекс на `user_id` для быстрого поиска всех привязанных провайдеров пользователя

### Требование 2: Инициация OAuth-потока (Authorization Request)

**User Story:** Как пользователь, я хочу нажать кнопку "Войти через Google" или "Войти через Apple", чтобы начать процесс OAuth-авторизации.

#### Критерии приёмки

1. WHEN пользователь запрашивает OAuth-авторизацию, THE Auth_API SHALL сгенерировать криптографически случайный State_Parameter длиной не менее 32 байт
2. WHEN пользователь запрашивает OAuth-авторизацию, THE Auth_API SHALL сгенерировать криптографически случайный Nonce длиной не менее 32 байт
3. WHEN пользователь запрашивает OAuth-авторизацию, THE Auth_API SHALL сохранить State_Parameter и Nonce в серверной сессии
4. WHEN пользователь запрашивает авторизацию через Google, THE Auth_API SHALL перенаправить на `https://accounts.google.com/o/oauth2/v2/auth` с параметрами `response_type=code`, `client_id`, `redirect_uri`, `scope=openid email profile`, `state` и `nonce`
5. WHEN пользователь запрашивает авторизацию через Apple, THE Auth_API SHALL перенаправить на `https://appleid.apple.com/auth/authorize` с параметрами `response_type=code id_token`, `response_mode=form_post`, `client_id`, `redirect_uri`, `scope=name email`, `state` и `nonce`

### Требование 3: Обработка OAuth Callback (Token Exchange)

**User Story:** Как система, я хочу безопасно обработать callback от провайдера, чтобы получить и верифицировать данные пользователя.

#### Критерии приёмки

1. WHEN OAuth_Service получает callback, THE OAuth_Service SHALL проверить что параметр `state` совпадает со значением, сохранённым в сессии
2. IF параметр `state` не совпадает или отсутствует, THEN THE OAuth_Service SHALL отклонить запрос с HTTP 403 и перенаправить пользователя на страницу входа с сообщением об ошибке
3. WHEN OAuth_Service получает authorization code от Google, THE OAuth_Service SHALL обменять код на токены через POST-запрос к `https://oauth2.googleapis.com/token` на стороне сервера
4. WHEN OAuth_Service получает authorization code от Apple, THE OAuth_Service SHALL обменять код на токены через POST-запрос к `https://appleid.apple.com/auth/token` на стороне сервера
5. WHEN OAuth_Service получает ID_Token от Google, THE OAuth_Service SHALL верифицировать подпись токена, используя публичные ключи Google (JWKS endpoint `https://www.googleapis.com/oauth2/v3/certs`)
6. WHEN OAuth_Service получает ID_Token от Apple, THE OAuth_Service SHALL верифицировать подпись токена, используя публичные ключи Apple (JWKS endpoint `https://appleid.apple.com/auth/keys`)
7. WHEN OAuth_Service верифицирует ID_Token, THE OAuth_Service SHALL проверить что claim `nonce` совпадает со значением, сохранённым в сессии
8. WHEN OAuth_Service верифицирует ID_Token, THE OAuth_Service SHALL проверить что claim `aud` совпадает с client_id приложения
9. WHEN OAuth_Service верифицирует ID_Token, THE OAuth_Service SHALL проверить что claim `iss` соответствует ожидаемому issuer провайдера
10. WHEN OAuth_Service верифицирует ID_Token, THE OAuth_Service SHALL проверить что claim `exp` не истёк
11. IF верификация ID_Token не проходит по любому из критериев, THEN THE OAuth_Service SHALL отклонить аутентификацию и перенаправить пользователя на страницу входа с сообщением об ошибке


### Требование 4: Регистрация нового пользователя через OAuth

**User Story:** Как новый пользователь, я хочу зарегистрироваться на платформе через Google или Apple, чтобы не создавать отдельный пароль.

#### Критерии приёмки

1. WHEN OAuth_Service получает верифицированный ID_Token и Provider_User_ID не найден в OAuth_Accounts_Table и email провайдера не найден в таблице `users`, THE OAuth_Service SHALL создать новую запись в таблице `users` с email провайдера, пустым паролем, `is_verified = 1` и сгенерированным никнеймом
2. WHEN OAuth_Service создаёт нового пользователя через OAuth, THE OAuth_Service SHALL создать запись в OAuth_Accounts_Table с `user_id`, `provider`, `provider_user_id` и `provider_email`
3. WHEN OAuth_Service создаёт нового пользователя через OAuth, THE OAuth_Service SHALL сгенерировать уникальный `ref_code` по той же логике, что и при обычной регистрации
4. WHEN OAuth_Service создаёт нового пользователя через OAuth, THE OAuth_Service SHALL записать `registration_ip` пользователя
5. WHEN OAuth_Service создаёт нового пользователя через OAuth, THE OAuth_Service SHALL выдать JWT access_token и refresh_token, аналогично обычному логину
6. WHEN OAuth_Service создаёт нового пользователя через Apple и Apple предоставляет Private_Relay_Email, THE OAuth_Service SHALL сохранить Private_Relay_Email как email пользователя

### Требование 5: Вход существующего пользователя через OAuth

**User Story:** Как существующий пользователь с привязанным OAuth-аккаунтом, я хочу входить на платформу через Google или Apple без ввода пароля.

#### Критерии приёмки

1. WHEN OAuth_Service получает верифицированный ID_Token и Provider_User_ID найден в OAuth_Accounts_Table, THE OAuth_Service SHALL аутентифицировать пользователя, связанного с этой записью
2. WHEN OAuth_Service аутентифицирует существующего пользователя через OAuth, THE OAuth_Service SHALL выдать JWT access_token и refresh_token, аналогично обычному логину
3. WHEN OAuth_Service аутентифицирует существующего пользователя через OAuth, THE OAuth_Service SHALL создать PHP-сессию для обратной совместимости с фронтендом
4. IF пользователь, связанный с OAuth-аккаунтом, имеет `is_banned = 1`, THEN THE OAuth_Service SHALL отклонить вход с HTTP 403 и сообщением о блокировке
5. IF пользователь, связанный с OAuth-аккаунтом, имеет `is_bot = 1`, THEN THE OAuth_Service SHALL отклонить вход с HTTP 403

### Требование 6: Автоматическая привязка OAuth к существующему email/password аккаунту

**User Story:** Как пользователь с существующим email/password аккаунтом, я хочу войти через Google с тем же email, чтобы мой OAuth-аккаунт автоматически привязался к моему существующему аккаунту.

#### Критерии приёмки

1. WHEN OAuth_Service получает верифицированный ID_Token, Provider_User_ID не найден в OAuth_Accounts_Table, но email провайдера совпадает с email существующего пользователя в таблице `users`, THE OAuth_Service SHALL создать запись в OAuth_Accounts_Table, привязывая OAuth-аккаунт к существующему пользователю
2. WHEN OAuth_Service привязывает OAuth-аккаунт к существующему пользователю, THE OAuth_Service SHALL аутентифицировать пользователя и выдать JWT-токены
3. WHEN OAuth_Service привязывает OAuth-аккаунт к существующему пользователю, THE OAuth_Service SHALL сохранить существующий пароль пользователя без изменений, позволяя вход как через OAuth, так и через email/password
4. IF существующий пользователь с совпадающим email имеет `is_verified = 0`, THEN THE OAuth_Service SHALL установить `is_verified = 1`, так как email подтверждён провайдером

### Требование 7: Обработка Private Relay Email от Apple

**User Story:** Как пользователь Apple, я хочу скрыть свой реальный email при регистрации, чтобы сохранить конфиденциальность.

#### Критерии приёмки

1. WHEN Apple предоставляет Private_Relay_Email (формат `*@privaterelay.appleid.com`), THE OAuth_Service SHALL использовать Private_Relay_Email как email пользователя
2. WHEN Apple предоставляет Private_Relay_Email и пользователь с таким email не существует, THE OAuth_Service SHALL создать нового пользователя с Private_Relay_Email
3. THE OAuth_Service SHALL идентифицировать пользователей Apple по Provider_User_ID (claim `sub`), а не по email, так как Private_Relay_Email может измениться

### Требование 8: Конфигурация OAuth-провайдеров

**User Story:** Как разработчик, я хочу хранить OAuth credentials в переменных окружения, чтобы не хранить секреты в коде.

#### Критерии приёмки

1. THE OAuth_Service SHALL читать Google Client ID из переменной окружения `GOOGLE_CLIENT_ID`
2. THE OAuth_Service SHALL читать Google Client Secret из переменной окружения `GOOGLE_CLIENT_SECRET`
3. THE OAuth_Service SHALL читать Apple Client ID (Services ID) из переменной окружения `APPLE_CLIENT_ID`
4. THE OAuth_Service SHALL читать Apple Team ID из переменной окружения `APPLE_TEAM_ID`
5. THE OAuth_Service SHALL читать Apple Key ID из переменной окружения `APPLE_KEY_ID`
6. THE OAuth_Service SHALL читать путь к Apple Private Key из переменной окружения `APPLE_PRIVATE_KEY_PATH`
7. THE OAuth_Service SHALL читать OAuth Redirect URI из переменной окружения `OAUTH_REDIRECT_URI`
8. IF любая обязательная переменная окружения для запрашиваемого провайдера отсутствует, THEN THE OAuth_Service SHALL вернуть HTTP 500 с сообщением о некорректной конфигурации и записать ошибку в лог


### Требование 9: Безопасность OAuth-потока

**User Story:** Как платформа, я хочу защитить OAuth-поток от атак, чтобы предотвратить несанкционированный доступ.

#### Критерии приёмки

1. THE OAuth_Service SHALL выполнять обмен authorization code на токены исключительно на стороне сервера (backend), передавая `client_secret` только в server-to-server запросах
2. THE OAuth_Service SHALL использовать HTTPS для всех запросов к OAuth-провайдерам
3. THE OAuth_Service SHALL удалять State_Parameter и Nonce из сессии сразу после успешной валидации, предотвращая повторное использование
4. THE OAuth_Service SHALL применять rate limiting к OAuth callback эндпоинтам — не более 10 запросов в минуту с одного IP
5. THE OAuth_Service SHALL логировать все попытки OAuth-аутентификации (успешные и неуспешные) через StructuredLogger с указанием провайдера, IP-адреса и User-Agent
6. THE OAuth_Service SHALL отклонять OAuth callback запросы, если сессия с сохранённым State_Parameter истекла или отсутствует

### Требование 10: OAuth-only пользователи (без пароля)

**User Story:** Как пользователь, зарегистрированный через OAuth, я хочу пользоваться платформой без необходимости устанавливать пароль.

#### Критерии приёмки

1. WHEN пользователь зарегистрирован только через OAuth, THE Auth_API SHALL хранить пустую строку в поле `password` таблицы `users`
2. WHEN пользователь с пустым паролем пытается войти через email/password форму, THE Auth_API SHALL отклонить попытку с сообщением "Используйте вход через Google или Apple"
3. THE Auth_API SHALL корректно обрабатывать `password_verify()` для пользователей с пустым паролем, не допуская входа по пустому паролю

### Требование 11: Кнопки OAuth на фронтенде

**User Story:** Как пользователь, я хочу видеть кнопки "Войти через Google" и "Войти через Apple" на страницах входа и регистрации.

#### Критерии приёмки

1. THE Frontend SHALL отображать кнопку "Sign in with Google" на страницах входа и регистрации, стилизованную согласно Google Brand Guidelines
2. THE Frontend SHALL отображать кнопку "Sign in with Apple" на страницах входа и регистрации, стилизованную согласно Apple Human Interface Guidelines
3. WHEN пользователь нажимает кнопку OAuth-провайдера, THE Frontend SHALL перенаправить браузер на эндпоинт Auth_API для инициации OAuth-потока
4. WHEN OAuth-поток завершается успешно, THE Frontend SHALL получить JWT-токены и обновить состояние AuthContext с данными пользователя
5. WHEN OAuth-поток завершается с ошибкой, THE Frontend SHALL отобразить сообщение об ошибке пользователю
6. THE Frontend SHALL обеспечить доступность (accessibility) кнопок OAuth: корректные `aria-label`, фокус с клавиатуры, контрастность текста

### Требование 12: Обработка OAuth Callback на фронтенде

**User Story:** Как система, я хочу корректно обработать редирект после OAuth-авторизации, чтобы пользователь оказался в авторизованном состоянии.

#### Критерии приёмки

1. THE Frontend SHALL иметь маршрут `/auth/callback` для обработки редиректов после OAuth-авторизации
2. WHEN Frontend получает редирект на `/auth/callback` с параметрами `access_token` и `refresh_token`, THE Frontend SHALL сохранить токены и обновить AuthContext
3. WHEN Frontend получает редирект на `/auth/callback` с параметром `error`, THE Frontend SHALL отобразить сообщение об ошибке и перенаправить на страницу входа
4. WHILE Frontend обрабатывает OAuth callback, THE Frontend SHALL отображать индикатор загрузки

### Требование 13: Миграция базы данных

**User Story:** Как разработчик, я хочу иметь миграцию для создания таблицы `oauth_accounts`, чтобы применить изменения к существующей базе данных.

#### Критерии приёмки

1. THE Auth_API SHALL предоставить PHP-миграцию в `backend/migrations/`, создающую таблицу `oauth_accounts`
2. THE миграция SHALL быть идемпотентной — повторный запуск не вызывает ошибок (`CREATE TABLE IF NOT EXISTS`)
3. THE миграция SHALL обновить поле `password` в таблице `users`, сделав его nullable (`DEFAULT ''`), для поддержки OAuth-only пользователей
