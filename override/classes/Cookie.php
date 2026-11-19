<?php

class Cookie extends CookieCore
{
    /**
     * Synchronously set or clear ps_logged cookie whenever authentication changes
     * so front-end caching can distinguish logged-in users immediately.
     */
    protected function encryptAndSetCookie($cookie = null)
    {
        $result = parent::encryptAndSetCookie($cookie);

        if (!headers_sent() && !$this->_standalone) {
            // Customer login tracking for cache bypass
            $isLogged = !empty($this->_content['logged']) && !empty($this->_content['id_customer']) && empty($this->_content['is_guest']);
            if ($isLogged) {
                setcookie('ps_logged', '1', [
                    'expires' => $this->_expire,
                    'path' => '/',
                    'domain' => (string) $this->_domain,
                    'secure' => $this->_secure,
                    'httponly' => false,
                    'samesite' => in_array((string) $this->_sameSite, static::SAMESITE_AVAILABLE_VALUES) ? (string) $this->_sameSite : static::SAMESITE_NONE,
                ]);
                $_COOKIE['ps_logged'] = '1';
            } else {
                if (isset($_COOKIE['ps_logged'])) {
                    setcookie('ps_logged', '', [
                        'expires' => 1,
                        'path' => '/',
                        'domain' => (string) $this->_domain,
                        'secure' => $this->_secure,
                        'httponly' => false,
                        'samesite' => in_array((string) $this->_sameSite, static::SAMESITE_AVAILABLE_VALUES) ? (string) $this->_sameSite : static::SAMESITE_NONE,
                    ]);
                    unset($_COOKIE['ps_logged']);
                }
            }
        }

        return $result;
    }

    /**
     * Throttle EmployeeSession / CustomerSession DB writes to avoid UPDATE queries on every page load.
     * Controlled via PS_REDIS_PERF_SESSION_THROTTLE_ENABLED and PS_REDIS_PERF_SESSION_THROTTLE_SECONDS.
     */
    public function getSession($sessionId)
    {
        $throttleEnabled = true;
        $throttleSeconds = 300;
        if (class_exists('Configuration', false) && Configuration::configurationIsLoaded()) {
            if (Configuration::getGlobalValue('PS_REDIS_PERF_SESSION_THROTTLE_ENABLED') === '0') {
                $throttleEnabled = false;
            }
            $configuredSeconds = (int) Configuration::getGlobalValue('PS_REDIS_PERF_SESSION_THROTTLE_SECONDS');
            if ($configuredSeconds >= 30 && $configuredSeconds <= 86400) {
                $throttleSeconds = $configuredSeconds;
            }
        }

        if (!$throttleEnabled) {
            return parent::getSession($sessionId);
        }

        if ($this->session !== null) {
            return $this->session;
        }

        if (isset($this->id_employee)) {
            $this->session = new EmployeeSession($sessionId);
        } elseif (isset($this->id_customer)) {
            $this->session = new CustomerSession($sessionId);
        }

        if (isset($this->session) && Validate::isLoadedObject($this->session)) {
            // Throttle session update: only save to DB if at least $throttleSeconds have elapsed since last update
            if (empty($this->session->date_upd) || (time() - strtotime($this->session->date_upd)) > $throttleSeconds) {
                $this->session->save();
            }
        }

        return $this->session;
    }
}
