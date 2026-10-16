"""Point d'entrée : python -m app"""
import uvicorn

from .config import settings


def main() -> None:
    if settings.host not in ("127.0.0.1", "localhost", "::1"):
        print(f"[ATTENTION] Le service IA écoute sur {settings.host}. "
              "Il ne doit pas être accessible depuis Internet : protégez-le par un pare-feu.")
    uvicorn.run("app.main:app", host=settings.host, port=settings.port, workers=1,
                log_level=settings.log_level.lower(), limit_concurrency=32, timeout_keep_alive=10)


if __name__ == "__main__":
    main()
