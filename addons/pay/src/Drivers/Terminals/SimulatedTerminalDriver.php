<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Terminals;

use Addons\Pay\Drivers\SimulationDriver;

/**
 * TPE SIMULÉ : le « client » présente sa carte sur un terminal fictif ; la demande passe « en cours »,
 * puis le terminal répond à la vérification suivante. Aucune somme réelle ; tout est marqué TEST.
 * config.scenario : pending (par défaut), success, decline, pending_forever, network_loss, invalid.
 */
final class SimulatedTerminalDriver extends SimulationDriver
{
    public static function info(): array
    {
        return ['code' => 'terminal_simulation', 'label' => 'TPE simulé (TEST)', 'kind' => 'terminal', 'transports' => ['none'], 'verified' => false,
            'notes' => 'Terminal fictif pour former les caissiers et tester la caisse. ' . self::MARK . '.'];
    }

    protected function stateName(): string
    {
        return 'terminal-simulation-' . (int) ($this->target['id'] ?? 0);
    }

    protected function sc(): string
    {
        return (string) ($this->config['scenario'] ?? 'pending');
    }
}
