# Composants vocaux livrés avec POS Marwa (dossier `addons/marwa_voice/piper`)

Ces composants sont des **programmes et données de tiers**, distribués tels quels (non modifiés,
seulement découpés en fragments de 1 Mo pour l'envoi par WampServer et reconstitués à
l'identique, empreinte SHA-256 vérifiée, dans `storage/voix/`). POS Marwa les exécute comme un
programme séparé ; ils ne font pas partie du code de POS Marwa.

| Composant | Version | Licence | Source |
|---|---|---|---|
| Piper (`piper.exe`) | 2023.11.14-2, Windows x64 | MIT — `licences/piper-MIT.txt` | https://github.com/rhasspy/piper |
| piper-phonemize (`piper_phonemize.dll`) | livrée avec Piper | MIT — `licences/piper-phonemize-MIT.txt` | https://github.com/rhasspy/piper-phonemize |
| ONNX Runtime (`onnxruntime*.dll`) | 1.14.1 | MIT — `licences/onnxruntime-MIT.txt` | https://github.com/microsoft/onnxruntime |
| eSpeak NG (`espeak-ng.dll`, `espeak-ng-data/`) | 1.52 (livrée avec Piper) | **GPL v3** — `licences/espeak-ng-GPL-3.0.txt` | https://github.com/espeak-ng/espeak-ng — code source correspondant : https://github.com/rhasspy/espeak-ng |
| Voix « siwis » medium (`fr-siwis-medium.onnx`) | piper v0.0.2 | **CC BY 4.0** | modèle : https://github.com/rhasspy/piper ; données : *SIWIS French Speech Synthesis Database*, University of Edinburgh, https://datashare.is.ed.ac.uk/handle/10283/2353 |

Attribution (CC BY 4.0) : voix de synthèse entraînée sur la « SIWIS French Speech Synthesis
Database » (Yamagishi et al., University of Edinburgh), https://creativecommons.org/licenses/by/4.0/.

Allègement : seuls les dictionnaires de prononciation français et anglais d'eSpeak NG sont
livrés (les autres langues ne servent pas à la voix française) ; le modèle arabe « tashkeel »
et les variantes de voix eSpeak sont retirés. Résultat vérifié identique (son généré octet pour
octet, synthèse sans aléa) à celui de l'archive complète.
