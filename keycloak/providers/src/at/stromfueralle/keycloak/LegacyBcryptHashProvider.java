package at.stromfueralle.keycloak;

import java.nio.charset.StandardCharsets;

import org.bouncycastle.crypto.generators.OpenBSDBCrypt;
import org.keycloak.credential.hash.PasswordHashProvider;
import org.keycloak.models.PasswordPolicy;
import org.keycloak.models.credential.PasswordCredentialModel;

/**
 * Prüft nur die alten bcrypt-Hashes ($2y$) aus der Plattform-Datenbank (vor dem 15.11.2026).
 *
 * Neue Hashes erzeugt dieser Provider nie. policyCheck() liefert immer false: Keycloak hasht das
 * Passwort deshalb nach der ersten erfolgreichen Anmeldung mit dem Verfahren der Passwort-Richtlinie
 * (Argon2) neu. Danach wird dieser Provider für den Benutzer nicht mehr gebraucht.
 *
 * Das bcrypt selbst kommt aus BouncyCastle, das Keycloak ohnehin mitliefert.
 */
public class LegacyBcryptHashProvider implements PasswordHashProvider {

    public static final String ID = "bcrypt-legacy";

    @Override
    public boolean policyCheck(PasswordPolicy policy, PasswordCredentialModel credential) {
        return false;
    }

    @Override
    public PasswordCredentialModel encodedCredential(String rawPassword, int iterations) {
        throw new UnsupportedOperationException("bcrypt-legacy erzeugt keine neuen Hashes");
    }

    @Override
    public boolean verify(String rawPassword, PasswordCredentialModel credential) {
        String hash = credential.getPasswordSecretData().getValue();
        if (rawPassword == null || hash == null || !hash.startsWith("$2")) {
            return false;
        }
        // PHP schreibt $2y$; BouncyCastle kennt 2a/2b/2y. Wie in PHP zählen nur die ersten 72 Bytes.
        byte[] pw = rawPassword.getBytes(StandardCharsets.UTF_8);
        try {
            return OpenBSDBCrypt.checkPassword(hash, pw);
        } catch (IllegalArgumentException | org.bouncycastle.crypto.DataLengthException e) {
            return false;
        }
    }

    @Override
    public void close() {
    }
}
