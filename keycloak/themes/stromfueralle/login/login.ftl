<#-- Wie keycloak.v2/login.ftl (Keycloak 26.8), dazu im Abschnitt "info" die Links zum Portal:
     "Passwort vergessen?" und "Mitglied werden". Keycloak verschickt selbst keine Mails, Passwörter
     setzt das Portal (docs/adr/0007-keycloak.md). Ziele kommen aus den Client-Attributen
     sfa.forgot.url und sfa.join.url (nur beim Client "portal" gesetzt). -->
<#import "template.ftl" as layout>
<#import "field.ftl" as field>
<#import "buttons.ftl" as buttons>
<#import "social-providers.ftl" as identityProviders>
<#import "passkeys.ftl" as passkeys>
<#assign sfaForgot = (client.attributes["sfa.forgot.url"])!"">
<#assign sfaJoin = (client.attributes["sfa.join.url"])!"">
<@layout.registrationLayout displayMessage=!messagesPerField.existsError('username','password') displayInfo=(sfaForgot?has_content || sfaJoin?has_content); section>
<!-- template: login.ftl (stromfueralle) -->

    <#if section = "header">
        ${msg("loginAccountTitle")}
    <#elseif section = "form">
        <div id="kc-form">
          <div id="kc-form-wrapper">
            <#if realm.password>
                <form id="kc-form-login" class="${properties.kcFormClass!}" onsubmit="login.disabled = true; return true;" action="${url.loginAction}" method="post" novalidate="novalidate">
                    <#if !usernameHidden??>
                        <@field.input name="username" label=msg("email") error=messagesPerField.getFirstError('username','password')
                            autofocus=true autocomplete="${(enableWebAuthnConditionalUI?has_content)?then('username webauthn', 'username')}" value=login.username!'' />
                        <@field.password name="password" label=msg("password") error="" forgotPassword=false autofocus=usernameHidden?? autocomplete="current-password">
                            <#if realm.rememberMe && !usernameHidden??>
                                <@field.checkbox name="rememberMe" label=msg("rememberMe") value=login.rememberMe?? />
                            </#if>
                        </@field.password>
                    <#else>
                        <@field.password name="password" label=msg("password") forgotPassword=false autofocus=usernameHidden?? autocomplete="current-password">
                            <#if realm.rememberMe && !usernameHidden??>
                                <@field.checkbox name="rememberMe" label=msg("rememberMe") value=login.rememberMe?? />
                            </#if>
                        </@field.password>
                    </#if>

                    <input type="hidden" id="id-hidden-input" name="credentialId" <#if auth.selectedCredential?has_content>value="${auth.selectedCredential}"</#if>/>
                    <@buttons.loginButton />
                </form>
            </#if>
            </div>
        </div>
        <@passkeys.conditionalUIData />
    <#elseif section = "socialProviders" >
        <#if realm.password && social.providers?? && social.providers?has_content>
            <@identityProviders.show social=social/>
        </#if>
    <#elseif section = "info" >
        <div id="sfa-links">
            <#if sfaForgot?has_content><a id="sfa-forgot" href="${sfaForgot}">${msg("sfaForgotPassword")}</a></#if>
            <#if sfaJoin?has_content><span>${msg("sfaNoAccount")} <a id="sfa-join" href="${sfaJoin}">${msg("sfaJoin")}</a></span></#if>
        </div>
    </#if>

</@layout.registrationLayout>
