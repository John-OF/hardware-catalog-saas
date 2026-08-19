import './ForgotPasswordPage.css';

import { useState } from 'react';
import { Link } from 'react-router-dom';
import { toast } from 'react-hot-toast';
import { Mail, Loader2, KeyRound, MailCheck, ArrowLeft } from 'lucide-react';
import { forgotPassword } from '../../api/auth';

/** Forma mínima de un error de axios tal como lo devuelve la API. */
type ApiError = {
  response?: { status?: number; data?: { message?: string } };
};

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [sent, setSent] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email) {
      toast.error('Escribe tu correo electrónico.');
      return;
    }

    setIsLoading(true);
    try {
      await forgotPassword(email);
      // El backend responde lo mismo exista o no el correo, así que la pantalla
      // de confirmación tampoco puede afirmar que se envió: diría de más.
      setSent(true);
    } catch (error) {
      console.error(error);
      const response = (error as ApiError).response;
      const message =
        response?.status === 429
          ? 'Demasiados intentos. Espera un minuto y vuelve a probar.'
          : response?.data?.message || 'No pudimos procesar la solicitud. Inténtalo de nuevo.';
      toast.error(message);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="auth-container animate-fade-in page-forgot-password">
      <div className="auth-card glass-card animate-scale-in">
        <div className="auth-header">
          <div className="logo-badge">{sent ? <MailCheck size={32} /> : <KeyRound size={32} />}</div>
          <h1>{sent ? 'Revisa tu correo' : '¿Olvidaste tu contraseña?'}</h1>
          <p>
            {sent
              ? 'Si ese correo pertenece a una tienda registrada, te enviamos un enlace para elegir una contraseña nueva. Caduca en 60 minutos.'
              : 'Escribe el correo con el que administras tu tienda y te enviamos un enlace para restablecerla.'}
          </p>
        </div>

        {sent ? (
          <div className="auth-form">
            <p className="auth-hint">
              ¿No te llegó? Revisa la carpeta de spam o{' '}
              <button type="button" className="link-button" onClick={() => setSent(false)}>
                prueba con otro correo
              </button>
              .
            </p>
            <Link to="/login" className="btn-primary btn-block btn-link">
              Volver al inicio de sesión
            </Link>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="auth-form">
            <div className="form-group">
              <label htmlFor="email">Correo Electrónico</label>
              <div className="input-wrapper">
                <Mail className="input-icon" size={18} />
                <input
                  id="email"
                  type="email"
                  placeholder="ejemplo@tienda.com"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
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
                  <Loader2 className="spinner" size={18} />
                  Enviando...
                </>
              ) : (
                'Enviarme el enlace'
              )}
            </button>

            <p className="auth-footer">
              <Link to="/login" className="back-link">
                <ArrowLeft size={14} /> Volver al inicio de sesión
              </Link>
            </p>
          </form>
        )}
      </div>

    </div>
  );
}
