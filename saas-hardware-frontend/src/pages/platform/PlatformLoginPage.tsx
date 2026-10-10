import './PlatformLoginPage.css';

import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { isAxiosError } from 'axios';
import { ArrowLeft, Lock, Mail, Eye, EyeOff, KeyRound, Loader2, ShieldCheck } from 'lucide-react';
import { pideSegundoPaso, platformLogin, platformLoginConCodigo, type SesionDePlataforma } from '../../api/platform';
import { avisarError } from '../../api/erroresDeFormulario';
import { usePlatformAuthStore } from '../../stores/platformAuthStore';

export default function PlatformLoginPage() {
  const navigate = useNavigate();
  const setPlatformAuth = usePlatformAuthStore((s) => s.setPlatformAuth);
  const isAuthenticated = usePlatformAuthStore((s) => s.isAuthenticated);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);

  /**
   * SEC-9: con la verificación en dos pasos activa, la contraseña no da la
   * sesión sino un desafío, y mientras lo hay se pide el código. El de la app
   * o, si el móvil no está, uno de los de recuperación.
   */
  const [desafio, setDesafio] = useState<string | null>(null);
  const [codigo, setCodigo] = useState('');
  const [conRecuperacion, setConRecuperacion] = useState(false);

  useEffect(() => {
    if (isAuthenticated) navigate('/platform', { replace: true });
  }, [isAuthenticated, navigate]);

  const entrar = (sesion: SesionDePlataforma) => {
    setPlatformAuth(sesion.token, sesion.user);
    navigate('/platform', { replace: true });
  };

  const volverALaContrasenia = () => {
    setDesafio(null);
    setCodigo('');
    setConRecuperacion(false);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    try {
      const respuesta = await platformLogin(email, password);

      if (pideSegundoPaso(respuesta)) {
        setDesafio(respuesta.desafio);
        setCodigo('');
        setConRecuperacion(false);
        return;
      }

      entrar(respuesta);
    } catch (error) {
      console.error(error);
      avisarError(error, { contexto: 'plataforma', si422: 'Las credenciales son incorrectas.' });
    } finally {
      setIsLoading(false);
    }
  };

  const enviarCodigo = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!desafio) return;

    setIsLoading(true);
    try {
      entrar(await platformLoginConCodigo(desafio, codigo));
    } catch (error) {
      console.error(error);
      avisarError(error, { contexto: 'plataforma', si422: 'El código no es correcto.' });

      // Si lo que falla es el desafío —caducó a los 5 minutos o se agotaron sus
      // intentos—, otro código no va a servir: vuelta a la contraseña. Si solo
      // falló el código, se borra para escribir el siguiente.
      if (isAxiosError(error) && error.response?.data?.errors?.desafio) {
        volverALaContrasenia();
      } else {
        setCodigo('');
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="auth-container animate-fade-in page-platform-login">
      <div className="auth-card glass-card animate-scale-in">
        <div className="auth-header">
          <div className="logo-badge">
            {desafio ? <KeyRound size={32} /> : <ShieldCheck size={32} />}
          </div>
          <h1>{desafio ? 'Verificación en dos pasos' : 'Administración de plataforma'}</h1>
          <p>
            {desafio
              ? conRecuperacion
                ? 'Escribe uno de los códigos de recuperación que guardaste al activarla. Cada uno sirve una sola vez.'
                : 'Abre tu app de autenticación y escribe el código de 6 cifras de esta cuenta.'
              : 'Acceso del operador. Esta no es la entrada del panel de tu tienda.'}
          </p>
        </div>

        {desafio ? (
          <form onSubmit={enviarCodigo} className="auth-form">
            <div className="form-group">
              <label htmlFor="codigo">{conRecuperacion ? 'Código de recuperación' : 'Código de verificación'}</label>
              <div className="input-wrapper">
                <KeyRound className="input-icon" size={18} />
                <input
                  id="codigo"
                  type="text"
                  inputMode={conRecuperacion ? 'text' : 'numeric'}
                  autoComplete="one-time-code"
                  placeholder={conRecuperacion ? 'XXXXX-XXXXX' : '123456'}
                  maxLength={conRecuperacion ? 11 : 7}
                  value={codigo}
                  onChange={(e) => setCodigo(e.target.value)}
                  disabled={isLoading}
                  className="premium-input with-icon"
                  autoFocus
                  required
                />
              </div>
            </div>

            <button type="submit" disabled={isLoading} className="btn-primary btn-block">
              {isLoading ? (
                <>
                  <Loader2 className="spinner" size={18} /> Comprobando...
                </>
              ) : (
                'Verificar'
              )}
            </button>

            <div className="dos-pasos-enlaces">
              <button
                type="button"
                className="link-button"
                onClick={() => {
                  setConRecuperacion(!conRecuperacion);
                  setCodigo('');
                }}
                disabled={isLoading}
              >
                {conRecuperacion ? 'Usar el código de la app' : '¿Sin el móvil? Usa un código de recuperación'}
              </button>
              <button type="button" className="link-button" onClick={volverALaContrasenia} disabled={isLoading}>
                <ArrowLeft size={14} /> Volver
              </button>
            </div>
          </form>
        ) : (
          <form onSubmit={handleSubmit} className="auth-form">
            <div className="form-group">
              <label htmlFor="email">Correo del operador</label>
              <div className="input-wrapper">
                <Mail className="input-icon" size={18} />
                <input
                  id="email"
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  disabled={isLoading}
                  className="premium-input with-icon"
                  autoFocus
                  required
                />
              </div>
            </div>

            <div className="form-group">
              <label htmlFor="password">Contraseña</label>
              <div className="input-wrapper">
                <Lock className="input-icon" size={18} />
                <input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  placeholder="••••••••"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  disabled={isLoading}
                  className="premium-input with-icon"
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="password-toggle"
                  disabled={isLoading}
                >
                  {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
            </div>

            <button type="submit" disabled={isLoading} className="btn-primary btn-block">
              {isLoading ? (
                <>
                  <Loader2 className="spinner" size={18} /> Entrando...
                </>
              ) : (
                'Entrar'
              )}
            </button>
          </form>
        )}
      </div>

    </div>
  );
}
