package at.stromfueralle.keycloak;

import org.keycloak.Config;
import org.keycloak.credential.hash.PasswordHashProvider;
import org.keycloak.credential.hash.PasswordHashProviderFactory;
import org.keycloak.models.KeycloakSession;
import org.keycloak.models.KeycloakSessionFactory;

public class LegacyBcryptHashProviderFactory implements PasswordHashProviderFactory {

    private static final LegacyBcryptHashProvider INSTANCE = new LegacyBcryptHashProvider();

    @Override
    public PasswordHashProvider create(KeycloakSession session) {
        return INSTANCE;
    }

    @Override
    public void init(Config.Scope config) {
    }

    @Override
    public void postInit(KeycloakSessionFactory factory) {
    }

    @Override
    public void close() {
    }

    @Override
    public String getId() {
        return LegacyBcryptHashProvider.ID;
    }
}
