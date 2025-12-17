<?php
/**
 * Simple helper for config UI.
 */
class PluginAutostatusConfig {
   public static function canUpdate(): bool {
      return Session::haveRight('config', UPDATE);
   }
}
