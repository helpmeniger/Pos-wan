"""Exporte un extracteur d'empreintes pré-entraîné (MobileNetV3-Small, torchvision) au format ONNX.

À exécuter UNE FOIS sur une machine connectée à Internet :
    pip install torch torchvision onnx
    python scripts/export_embedder.py models/embedder.onnx
Puis copier models/embedder.onnx sur l'ordinateur du magasin (fonctionnement hors ligne ensuite)
et installer onnxruntime (requirements-optional.txt). Redémarrer le service IA puis relancer la
préparation de la reconnaissance (les galeries dépendent de l'extracteur utilisé).

Licences : torchvision (BSD-3). Les poids ImageNet sont fournis par PyTorch ; vérifiez leurs
conditions d'utilisation pour un usage commercial.
"""
import sys
from pathlib import Path

import torch
import torchvision


def main(out: Path) -> None:
    weights = torchvision.models.MobileNet_V3_Small_Weights.IMAGENET1K_V1
    model = torchvision.models.mobilenet_v3_small(weights=weights)
    model.classifier = torch.nn.Identity()  # sortie : vecteur de 576 caractéristiques
    model.eval()
    dummy = torch.randn(1, 3, 224, 224)
    out.parent.mkdir(parents=True, exist_ok=True)
    torch.onnx.export(model, dummy, str(out), input_names=["input"], output_names=["embedding"],
                      dynamic_axes={"input": {0: "batch"}, "embedding": {0: "batch"}}, opset_version=17)
    print(f"Modèle exporté : {out}")


if __name__ == "__main__":
    main(Path(sys.argv[1] if len(sys.argv) > 1 else "models/embedder.onnx"))
