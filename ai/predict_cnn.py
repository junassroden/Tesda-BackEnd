from pathlib import Path
import sys

import torch
import torch.nn as nn
from PIL import Image
from torchvision import transforms

PROJECT_DIR = Path(__file__).resolve().parent
MODEL_PATH = PROJECT_DIR / "models" / "custom_cnn_best.pth"
DEVICE = torch.device("cuda" if torch.cuda.is_available() else "cpu")


class ConvBlock(nn.Module):
    def __init__(self, in_channels, out_channels):
        super().__init__()
        self.block = nn.Sequential(
            nn.Conv2d(in_channels, out_channels, kernel_size=3, padding=1, bias=False),
            nn.BatchNorm2d(out_channels),
            nn.ReLU(inplace=True),
            nn.Conv2d(out_channels, out_channels, kernel_size=3, padding=1, bias=False),
            nn.BatchNorm2d(out_channels),
            nn.ReLU(inplace=True),
            nn.MaxPool2d(kernel_size=2),
        )

    def forward(self, x):
        return self.block(x)


class CustomCNN(nn.Module):
    def __init__(self, num_classes):
        super().__init__()
        self.features = nn.Sequential(
            ConvBlock(3, 32),
            ConvBlock(32, 64),
            ConvBlock(64, 128),
            ConvBlock(128, 192),
        )
        self.pool = nn.AdaptiveAvgPool2d((1, 1))
        self.classifier = nn.Sequential(
            nn.Flatten(),
            nn.Linear(192, 256),
            nn.ReLU(inplace=True),
            nn.Dropout(0.4),
            nn.Linear(256, num_classes),
        )

    def forward(self, x):
        return self.classifier(self.pool(self.features(x)))


def load_model():
    if not MODEL_PATH.is_file():
        raise FileNotFoundError(f"Document CNN checkpoint not found: {MODEL_PATH}")

    checkpoint = torch.load(MODEL_PATH, map_location=DEVICE, weights_only=True)
    class_names = checkpoint["class_names"]
    image_size = checkpoint.get("image_size", 128)
    model = CustomCNN(num_classes=checkpoint["num_classes"])
    model.load_state_dict(checkpoint["model_state_dict"])
    model.to(DEVICE)
    model.eval()

    transform = transforms.Compose([
        transforms.Resize((image_size, image_size)),
        transforms.ToTensor(),
        transforms.Normalize(mean=[0.5, 0.5, 0.5], std=[0.5, 0.5, 0.5]),
    ])
    return model, class_names, transform


def predict_image(image_path, model, class_names, transform):
    if isinstance(image_path, Image.Image):
        image = image_path.convert("RGB")
    else:
        image_path = Path(image_path)
        if not image_path.is_file():
            raise FileNotFoundError(f"Image not found: {image_path}")

        with Image.open(image_path) as source:
            image = source.convert("RGB")

    input_tensor = transform(image).unsqueeze(0).to(DEVICE)
    with torch.inference_mode():
        probabilities = torch.softmax(model(input_tensor), dim=1)[0]

    count = min(3, len(class_names))
    top_probabilities, top_indices = torch.topk(probabilities, k=count)
    predictions = [
        {
            "label": class_names[index],
            "confidence": round(float(probability) * 100, 2),
        }
        for probability, index in zip(top_probabilities.tolist(), top_indices.tolist())
    ]
    return predictions


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("Pass one document image path.")
    cnn, labels, image_transform = load_model()
    print(predict_image(sys.argv[1], cnn, labels, image_transform))
