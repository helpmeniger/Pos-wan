<?php
declare(strict_types=1);

namespace Addons\Clinique;

/**
 * Récepteur DICOM (rôle « Storage SCP », norme DICOM PS3.7 / PS3.8) écrit en PHP :
 * les appareils d'imagerie (radio numérisée CR/DR, échographe, scanner, mammographe…) envoient
 * leurs images par « DICOM Send / C-STORE » à POS Marwa, comme à un PACS.
 * - Vérification de connexion (C-ECHO), stockage (C-STORE) de toute classe d'image.
 * - Négociation : premier format affichable proposé par l'appareil (non compressé, RLE, JPEG baseline),
 *   sinon le premier format proposé est accepté et le fichier conservé intact.
 * - Un fichier reçu est écrit en DICOM Part 10, puis remis à $onStore (classement, rattachement).
 * - Associations traitées l'une après l'autre (pas de fork sous Windows) ; délais d'attente pour
 *   qu'un appareil muet ne bloque pas le récepteur.
 */
final class DicomServer
{
    public const VERIFICATION = '1.2.840.10008.1.1';
    public const APP_CONTEXT = '1.2.840.10008.3.1.1.1';
    public const IMPL_UID = '1.2.826.0.1.3680043.10.1395.1';
    public const IMPL_NAME = 'POSMARWA_IMG';
    private const MAX_PDU = 16 * 1024 * 1024;

    /** @var resource|null */
    private $sock;
    private string $peer = '';
    private string $callingAe = '';
    private array $contexts = [];

    /**
     * @param callable(string $file, array $ctx): void $onStore  fichier Part 10 reçu
     * @param callable(string $msg): void $log
     * @param ?callable(string $calling, string $ip): bool $allow  appareil autorisé ?
     */
    public function __construct(private string $aeTitle, private $onStore, private $log, private $allow = null, private string $tmpDir = '')
    {
        $this->aeTitle = strtoupper(trim($aeTitle)) ?: 'POSMARWA';
        $this->tmpDir = $tmpDir ?: sys_get_temp_dir();
    }

    /** Traite une connexion acceptée jusqu'à la libération de l'association. */
    public function handle($conn, string $peer): void
    {
        $this->sock = $conn;
        $this->peer = $peer;
        $this->contexts = [];
        stream_set_timeout($conn, 30);
        try {
            $pdu = $this->readPdu();
            if (!$pdu || $pdu['type'] !== 0x01) {
                $this->abort();
                return;
            }
            if (!$this->associate($pdu['data'])) {
                return;
            }
            $this->loop();
        } catch (\Throwable $e) {
            ($this->log)('Erreur avec ' . $peer . ' : ' . $e->getMessage());
            $this->abort();
        } finally {
            if (is_resource($conn)) {
                @fclose($conn);
            }
        }
    }

    // ------------------------------------------------------------------ PDU

    private function readExact(int $n): string
    {
        $s = '';
        while (strlen($s) < $n) {
            $c = fread($this->sock, min(65536, $n - strlen($s)));
            if ($c === false || $c === '') {
                $meta = stream_get_meta_data($this->sock);
                if ($meta['timed_out'] || feof($this->sock)) {
                    throw new \RuntimeException($meta['timed_out'] ? 'délai dépassé' : 'connexion fermée');
                }
                continue;
            }
            $s .= $c;
        }
        return $s;
    }

    private function readPdu(): ?array
    {
        $h = $this->readExact(6);
        $type = ord($h[0]);
        $len = unpack('N', substr($h, 2, 4))[1];
        if ($len > self::MAX_PDU) {
            throw new \RuntimeException('PDU trop grand (' . $len . ' octets)');
        }
        return ['type' => $type, 'data' => $this->readExact($len)];
    }

    private function send(int $type, string $data): void
    {
        $buf = chr($type) . "\0" . pack('N', strlen($data)) . $data;
        for ($off = 0, $n = strlen($buf); $off < $n;) {
            $w = fwrite($this->sock, substr($buf, $off, 65536));
            if ($w === false || $w === 0) {
                throw new \RuntimeException('écriture impossible');
            }
            $off += $w;
        }
    }

    private function abort(): void
    {
        if (is_resource($this->sock)) {
            try {
                $this->send(0x07, "\0\0\0\0"); // A-ABORT (source : utilisateur du service)
            } catch (\Throwable) {
                // déjà fermé
            }
        }
    }

    private static function ae(string $s): string
    {
        return str_pad(substr($s, 0, 16), 16, ' ');
    }

    private static function item(int $type, string $data): string
    {
        return chr($type) . "\0" . pack('n', strlen($data)) . $data;
    }

    private static function cleanUid(string $s): string
    {
        return rtrim($s, "\0 ");
    }

    // ------------------------------------------------------------------ association

    private function associate(string $d): bool
    {
        $called = strtoupper(trim(substr($d, 4, 16)));
        $this->callingAe = strtoupper(trim(substr($d, 20, 16)));
        $ip = preg_replace('/:\d+$/', '', $this->peer);
        if ($called !== $this->aeTitle) {
            ($this->log)("Refus de {$this->callingAe} ({$this->peer}) : AE appelé « $called » au lieu de « {$this->aeTitle} »");
            $this->send(0x03, "\0" . chr(1) . chr(1) . chr(7)); // A-ASSOCIATE-RJ : AE appelé non reconnu
            return false;
        }
        if ($this->allow && !($this->allow)($this->callingAe, $ip)) {
            ($this->log)("Refus de {$this->callingAe} ({$this->peer}) : appareil non autorisé");
            $this->send(0x03, "\0" . chr(1) . chr(1) . chr(3)); // AE appelant non reconnu
            return false;
        }
        // Éléments variables : contexte d'application, contextes de présentation, informations utilisateur
        $pos = 68;
        $accept = '';
        $n = strlen($d);
        while ($pos + 4 <= $n) {
            $type = ord($d[$pos]);
            $len = unpack('n', substr($d, $pos + 2, 2))[1];
            $body = substr($d, $pos + 4, $len);
            $pos += 4 + $len;
            if ($type !== 0x20) {
                continue;
            }
            $pcId = ord($body[0]);
            $abstract = '';
            $tss = [];
            for ($p = 4; $p + 4 <= strlen($body);) {
                $st = ord($body[$p]);
                $sl = unpack('n', substr($body, $p + 2, 2))[1];
                $val = self::cleanUid(substr($body, $p + 4, $sl));
                $p += 4 + $sl;
                if ($st === 0x30) {
                    $abstract = $val;
                } elseif ($st === 0x40) {
                    $tss[] = $val;
                }
            }
            $isStorage = str_starts_with($abstract, '1.2.840.10008.5.1.4.1.1.');
            $result = 0;
            $chosen = '';
            if ($abstract !== self::VERIFICATION && !$isStorage) {
                $result = 3; // syntaxe abstraite non prise en charge
            } else {
                // Premier format affichable dans l'ordre de l'appareil (son format d'origine, sans conversion)
                foreach ($tss as $ts) {
                    if (in_array($ts, Dicom::RENDERABLE_TS, true)) {
                        $chosen = $ts;
                        break;
                    }
                }
                // Aucun format affichable proposé : on accepte le premier pour ne rien perdre
                $chosen = $chosen ?: ($tss[0] ?? '');
                if ($chosen === '') {
                    $result = 4;
                }
            }
            if ($result === 0) {
                $this->contexts[$pcId] = ['abstract' => $abstract, 'ts' => $chosen];
            }
            $accept .= self::item(0x21, chr($pcId) . "\0" . chr($result) . "\0" . self::item(0x40, $chosen !== '' ? $chosen : Dicom::TS_IMPLICIT));
        }
        if (!$this->contexts) {
            ($this->log)("Refus de {$this->callingAe} : aucun service pris en charge");
            $this->send(0x03, "\0" . chr(1) . chr(1) . chr(1));
            return false;
        }
        $user = self::item(0x51, pack('N', self::MAX_PDU)) . self::item(0x52, self::IMPL_UID) . self::item(0x55, self::IMPL_NAME);
        $ac = pack('n', 1) . "\0\0" . substr($d, 4, 16) . substr($d, 20, 16) . str_repeat("\0", 32)
            . self::item(0x10, self::APP_CONTEXT) . $accept . self::item(0x50, $user);
        $this->send(0x02, $ac);
        return true;
    }

    // ------------------------------------------------------------------ messages DIMSE

    private function loop(): void
    {
        $cmd = '';
        $data = null;
        $pc = 0;
        $tmp = null;
        while (true) {
            $pdu = $this->readPdu();
            if ($pdu['type'] === 0x05) { // A-RELEASE-RQ
                $this->send(0x06, "\0\0\0\0");
                return;
            }
            if ($pdu['type'] === 0x07) { // A-ABORT
                ($this->log)("Association interrompue par {$this->callingAe}");
                if ($tmp && is_file($tmp)) {
                    @unlink($tmp);
                }
                return;
            }
            if ($pdu['type'] !== 0x04) {
                $this->abort();
                return;
            }
            $d = $pdu['data'];
            for ($p = 0, $n = strlen($d); $p + 6 <= $n;) {
                $len = unpack('N', substr($d, $p, 4))[1];
                $pc = ord($d[$p + 4]);
                $mch = ord($d[$p + 5]);
                $frag = substr($d, $p + 6, $len - 2);
                $p += 4 + $len;
                if ($mch & 1) {
                    $cmd .= $frag;
                    if ($mch & 2) {
                        $c = self::parseCommand($cmd);
                        $cmd = '';
                        $field = $c['00000100'] ?? 0;
                        if ($field === 0x0030) { // C-ECHO-RQ
                            $this->respond($pc, 0x8030, $c, 0x0000);
                            ($this->log)("Test de connexion (C-ECHO) de {$this->callingAe} ({$this->peer}) : OK");
                            continue;
                        }
                        if ($field === 0x0001) { // C-STORE-RQ : le jeu de données suit
                            $data = $c;
                            $tmp = tempnam($this->tmpDir, 'rx');
                            continue;
                        }
                        $this->respond($pc, ($field | 0x8000) & 0xFFFF, $c, 0x0211); // opération non prise en charge
                    }
                } else {
                    if ($data === null || !$tmp) {
                        throw new \RuntimeException('données reçues sans commande');
                    }
                    file_put_contents($tmp, $frag, FILE_APPEND);
                    if ($mch & 2) {
                        $status = $this->store($pc, $data, $tmp);
                        $this->respond($pc, 0x8001, $data, $status);
                        $data = null;
                        $tmp = null;
                    }
                }
            }
        }
    }

    /** Écrit le fichier Part 10 et le remet au classement ; retourne le statut DICOM. */
    private function store(int $pc, array $c, string $dataset): int
    {
        $ctx = $this->contexts[$pc] ?? null;
        $sopClass = self::cleanUid((string) ($c['00000002'] ?? ''));
        $sopUid = self::cleanUid((string) ($c['00001000'] ?? ''));
        if (!$ctx || $sopUid === '') {
            @unlink($dataset);
            return 0xA900; // le jeu de données ne correspond pas à la classe
        }
        $file = $dataset . '.dcm';
        try {
            $out = fopen($file, 'wb');
            fwrite($out, str_repeat("\0", 128) . 'DICM' . self::fileMeta($sopClass, $sopUid, $ctx['ts'], $this->callingAe));
            $in = fopen($dataset, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            @unlink($dataset);
            ($this->onStore)($file, ['ae' => $this->callingAe, 'ip' => preg_replace('/:\d+$/', '', $this->peer), 'ts' => $ctx['ts']]);
            return 0x0000;
        } catch (\Throwable $e) {
            ($this->log)('Image refusée (' . $sopUid . ') : ' . $e->getMessage());
            @unlink($dataset);
            @unlink($file);
            return 0xC000; // ne peut pas être compris / enregistré
        }
    }

    /** Groupe 0002 en Explicit VR Little Endian. */
    public static function fileMeta(string $sopClass, string $sopUid, string $ts, string $sourceAe = ''): string
    {
        $el = function (int $e, string $vr, string $v): string {
            if (strlen($v) % 2) {
                $v .= in_array($vr, ['UI', 'OB'], true) ? "\0" : ' ';
            }
            if ($vr === 'OB') {
                return pack('vv', 0x0002, $e) . 'OB' . "\0\0" . pack('V', strlen($v)) . $v;
            }
            return pack('vv', 0x0002, $e) . $vr . pack('v', strlen($v)) . $v;
        };
        $body = $el(0x0001, 'OB', "\0\1") . $el(0x0002, 'UI', $sopClass) . $el(0x0003, 'UI', $sopUid) . $el(0x0010, 'UI', $ts)
            . $el(0x0012, 'UI', self::IMPL_UID) . $el(0x0013, 'SH', self::IMPL_NAME) . ($sourceAe !== '' ? $el(0x0016, 'AE', $sourceAe) : '');
        return pack('vv', 0x0002, 0x0000) . 'UL' . pack('v', 4) . pack('V', strlen($body)) . $body;
    }

    /** Commande (groupe 0000, toujours Implicit VR Little Endian). */
    private static function parseCommand(string $b): array
    {
        $out = [];
        for ($p = 0, $n = strlen($b); $p + 8 <= $n;) {
            $g = unpack('v', substr($b, $p, 2))[1];
            $e = unpack('v', substr($b, $p + 2, 2))[1];
            $l = unpack('V', substr($b, $p + 4, 4))[1];
            $v = substr($b, $p + 8, $l);
            $p += 8 + $l;
            $tag = sprintf('%04X%04X', $g, $e);
            $out[$tag] = match ($tag) {
                '00000100', '00000110', '00000700', '00000800', '00000900' => strlen($v) >= 2 ? unpack('v', $v)[1] : 0,
                default => $v,
            };
        }
        return $out;
    }

    private function respond(int $pc, int $field, array $rq, int $status): void
    {
        $el = function (int $e, string $v): string {
            return pack('vvV', 0x0000, $e, strlen($v)) . $v;
        };
        $uid = fn(string $s): string => strlen($s) % 2 ? $s . "\0" : $s;
        $body = '';
        if (isset($rq['00000002'])) {
            $body .= $el(0x0002, $uid(self::cleanUid((string) $rq['00000002'])));
        }
        $body .= $el(0x0100, pack('v', $field)) . $el(0x0120, pack('v', (int) ($rq['00000110'] ?? 0))) . $el(0x0800, pack('v', 0x0101)) . $el(0x0900, pack('v', $status));
        if (isset($rq['00001000'])) {
            $body .= $el(0x1000, $uid(self::cleanUid((string) $rq['00001000'])));
        }
        $cmd = $el(0x0000, pack('V', strlen($body))) . $body;
        $pdv = pack('N', strlen($cmd) + 2) . chr($pc) . chr(0x03) . $cmd; // commande, dernier fragment
        $this->send(0x04, $pdv);
    }
}
